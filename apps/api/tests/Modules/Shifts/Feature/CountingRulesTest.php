<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Contracts\LedgerOracle;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Contracts\Replay;
use App\Modules\Shifts\Enums\CountDecision;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Enums\StationCode;
use App\Shared\Events\VarianceDecided;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * The counting rules (Ramin 2026-11-12, ADR 0026): per station, the admin switches the opening count, the co-signature and the manager's
 * approval on and off in Admin > System > Counting rules (GET and PUT /counting-rules, shifts.manage). A rule applies to the next start,
 * submit or co-signature; counts already waiting keep waiting. Co-sign is its own switch: the production overseer (requires_overseer) is
 * untouched by it. Monday 2026-11-02, the kitchen counts in grams.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->f->stock($this->flour, $this->place, '10000');
    $this->rules = fn (array $stations, ?array $headers = null) => $this->f->send('PUT', '/api/v1/counting-rules', $this->f->admin, ['stations' => $stations], $headers ?? []);
    $this->kitchen = fn (): Station => Station::query()->findOrFail($this->f->kitchen->id);
    $this->closeShort = function (int $sequence = 1): int {
        $shift = $this->f->running($this->f->kitchen, $this->f->chef, ['sequence' => $sequence]);
        $count = (int) $this->f->close($this->f->chef, $shift->id)->assertOk()->json('counts.0.id');
        $this->f->lines($this->f->chef, $count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();

        return $count;
    };
    $this->onHand = fn (): string => (string) DB::table('stock_balances')->where('item_id', $this->flour->id)->where('location_id', $this->place->id)->value('on_hand');
});

it('lists the stations that run shifts with their three rules and how many counts wait now', function () {
    loginAs($this->f->admin);
    $response = $this->getJson('/api/v1/counting-rules')->assertOk();

    $codes = collect($response->json('data.stations'))->pluck('code')->all();
    expect($codes)->not->toContain('store', 'the warehouse runs no shifts')
        ->and($codes)->toContain('kitchen', 'till', 'cake_room');
    $kitchen = collect($response->json('data.stations'))->firstWhere('code', 'kitchen');
    expect($kitchen)->toMatchArray(['id' => $this->f->kitchen->id, 'opening_count' => true, 'cosign' => false, 'approval' => true])
        ->and($kitchen)->not->toHaveKey('requires_overseer');
    $response->assertJsonPath('data.waiting', ['awaiting_cosign' => 0, 'pending_approval' => 0]);
});

it('switches rules for several stations at once, one audit row per station changed, none for a station already so', function () {
    $audit = AuditLog::query()->where('action', 'station.counting_rules')->count();

    ($this->rules)([
        ['id' => $this->f->kitchen->id, 'opening_count' => false, 'cosign' => true],
        ['id' => $this->f->till->id, 'approval' => false],
        ['id' => $this->f->cakeRoom->id, 'opening_count' => true],
    ])->assertOk()
        ->assertJsonPath('data.stations.'.collect($this->getJson('/api/v1/counting-rules')->json('data.stations'))->search(fn ($s) => $s['code'] === 'kitchen').'.cosign', true);

    expect(($this->kitchen)()->count_opening)->toBeFalse()
        ->and(($this->kitchen)()->count_cosign)->toBeTrue()
        ->and(($this->kitchen)()->count_approval)->toBeTrue()
        ->and(Station::query()->findOrFail($this->f->till->id)->count_approval)->toBeFalse()
        ->and(($this->kitchen)()->requires_overseer)->toBeFalse('the production overseer is its own switch');
    $rows = AuditLog::query()->where('action', 'station.counting_rules')->orderBy('id')->get();
    expect($rows->count() - $audit)->toBe(2, 'the cake room already counted at opening')
        ->and($rows->firstWhere('subject_id', $this->f->kitchen->id)?->before['count_opening'])->toBeIn([1, true])
        ->and($rows->firstWhere('subject_id', $this->f->kitchen->id)?->after['count_opening'])->toBeIn([0, false]);
});

it('changes nothing when one station of the request is refused: all or none', function () {
    $other = actInBranch(Branch::factory()->create());
    $foreign = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $other->id]);
    actInBranch($this->branch);

    ($this->rules)([['id' => $this->f->kitchen->id, 'opening_count' => false], ['id' => $foreign->id, 'opening_count' => false]])
        ->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->rules)([['id' => $this->f->store->id, 'cosign' => true]])->assertStatus(422);
    ($this->rules)([['id' => $this->f->kitchen->id]])->assertStatus(422)->assertJsonStructure(['errors' => ['stations.0']]);
    ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => true], ['id' => $this->f->kitchen->id, 'cosign' => false]])->assertStatus(422);
    ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => 'maybe']])->assertStatus(422);

    expect(($this->kitchen)()->count_opening)->toBeTrue()->and(($this->kitchen)()->count_cosign)->toBeFalse();
});

it('is the admin\'s alone, and answers the same Idempotency-Key once', function () {
    foreach ([$this->f->manager, $this->f->cashier, $this->f->chef, $this->f->waiter, $this->f->baker, $this->f->breadBaker] as $who) {
        loginAs($who);
        $this->getJson('/api/v1/counting-rules')->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
        $this->f->send('PUT', '/api/v1/counting-rules', $who, ['stations' => [['id' => $this->f->kitchen->id, 'cosign' => true]]])->assertForbidden();
    }
    expect(($this->kitchen)()->count_cosign)->toBeFalse();

    $headers = idempotencyKey();
    $first = ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => true]], $headers)->assertOk();
    $audit = AuditLog::query()->count();
    $second = ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => true]], $headers)->assertOk();
    expect($second->json())->toBe($first->json())->and(AuditLog::query()->count())->toBe($audit);
});

it('opens a shift at once, carried, where the opening count is off, and only there', function () {
    ($this->rules)([['id' => $this->f->kitchen->id, 'opening_count' => false]])->assertOk();

    $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated()
        ->assertJsonPath('shift.status', 'open')
        ->assertJsonPath('shift.opening_mode', 'carried')
        ->assertJsonPath('counts', []);
    expect(StockCount::query()->count())->toBe(0);

    // the till still counts at opening: the rule is the station's
    $this->f->drawer($this->f->till);
    $this->f->start($this->f->cashier, $this->f->till)->assertCreated()->assertJsonPath('shift.status', 'opening');
});

it('sends a count straight to its decision where co-sign is off, and to the co-signer where it is on', function () {
    $count = ($this->closeShort)();
    $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'pending_approval');

    ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => true]])->assertOk();
    $this->f->approve($this->f->admin, $count)->assertOk();
    $next = ($this->closeShort)(2);
    $this->f->submit($this->f->chef, $next)->assertOk()->assertJsonPath('status', 'awaiting_cosign');
});

it('approves a count that differs by the rule where manager approval is off: the adjustment, the lock, a sound chain, no decider', function () {
    ($this->rules)([['id' => $this->f->kitchen->id, 'approval' => false]])->assertOk();
    Event::fake([VarianceDecided::class]);
    $count = ($this->closeShort)();

    $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'locked');

    $row = StockCount::query()->findOrFail($count);
    expect($row->decision)->toBe(CountDecision::Approved)
        ->and($row->decided_by_user_id)->toBeNull('the rule approved it, not a person')
        ->and($row->decided_at)->not->toBeNull()
        ->and($row->locked_by_user_id)->toBe($this->f->chef->id)
        ->and(($this->onHand)())->toBe('9500.0000')
        ->and(DB::table('stock_movements')->where('reason', 'count_adjustment')->count())->toBe(1)
        ->and(app(HashChain::class)->verify($this->place->id)->toArray())->toMatchArray(['ok' => true, 'checked' => 1])
        ->and(app(HashChain::class)->verifyAll(DB::getDefaultConnection())->ok())->toBeTrue()
        ->and(app(LedgerOracle::class)->check(DB::getDefaultConnection())->ok())->toBeTrue();
    $payload = json_decode((string) $row->canonical_payload, true);
    expect($payload['decision'])->toBe('approved')->and($payload['decided_by_user_id'])->toBeNull();
    $audit = AuditLog::query()->where('action', 'count.approve_by_rule')->sole();
    expect($audit->subject_id)->toBe($count)->and($audit->after['adjusted_lines'])->toBe(1)->and($audit->actor_id)->toBe($this->f->chef->id);
    expect(ShiftInstance::query()->sole()->status->value)->toBe('locked', 'its only count resolved');
    Event::assertNotDispatched(VarianceDecided::class);
});

it('approves by the rule right after the co-signature where co-sign is on and manager approval off', function () {
    ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => true, 'approval' => false]])->assertOk();
    $this->f->overseer($this->f->cashier2, $this->f->kitchen);
    $count = ($this->closeShort)();
    $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'awaiting_cosign');
    expect(($this->onHand)())->toBe('10000.0000');

    $this->f->cosign($this->f->cashier2, $count)->assertOk()->assertJsonPath('status', 'locked');

    $row = StockCount::query()->findOrFail($count);
    expect($row->cosigned_by_user_id)->toBe($this->f->cashier2->id)
        ->and($row->decided_by_user_id)->toBeNull()
        ->and($row->locked_by_user_id)->toBe($this->f->cashier2->id)
        ->and(($this->onHand)())->toBe('9500.0000')
        ->and(app(HashChain::class)->verify($this->place->id)->toArray())->toMatchArray(['ok' => true]);
});

it('leaves a count that already waits as it is when a rule is switched off', function () {
    ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => true]])->assertOk();
    $count = ($this->closeShort)();
    $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'awaiting_cosign');
    loginAs($this->f->admin);
    $this->getJson('/api/v1/counting-rules')->assertJsonPath('data.waiting.awaiting_cosign', 1);

    ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => false, 'approval' => false]])->assertOk()
        ->assertJsonPath('data.waiting.awaiting_cosign', 1);

    expect(StockCount::query()->findOrFail($count)->status)->toBe(CountStatus::AwaitingCosign, 'a switch does not release a waiting count')
        ->and(($this->onHand)())->toBe('10000.0000');
    // the co-signature it waited for still comes, and the rule in force then approves it
    $this->f->cosign($this->f->admin, $count)->assertOk()->assertJsonPath('status', 'locked');
});

it('lets the count wait for a person when the rule cannot approve it (stock moved so far an adjustment would go below zero)', function () {
    ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => true, 'approval' => false]])->assertOk();
    $this->f->overseer($this->f->cashier2, $this->f->kitchen);
    $shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $count = (int) $this->f->close($this->f->chef, $shift->id)->assertOk()->json('counts.0.id');
    // counted 500 g short of 10 000, then 9 800 g leave before the co-signature: 200 g on hand cannot take a 500 g correction
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
    $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'awaiting_cosign');
    $this->f->stock($this->flour, $this->place, '-9800', MovementReason::Waste);

    $this->f->cosign($this->f->cashier2, $count)->assertOk()->assertJsonPath('status', 'pending_approval');

    expect(AuditLog::query()->where('action', 'count.approve_by_rule_skipped')->where('subject_id', $count)->count())->toBe(1)
        ->and(DB::table('stock_movements')->where('reason', 'count_adjustment')->count())->toBe(0);
});

it('replays a count by what it did, not by the co-sign rule switched since', function () {
    ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => true]])->assertOk();
    $this->f->overseer($this->f->cashier2, $this->f->kitchen);
    $count = ($this->closeShort)();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->f->submit($this->f->chef, $count)->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:30:00', 'UTC'));
    $this->f->cosign($this->f->cashier2, $count)->assertOk()->assertJsonPath('status', 'pending_approval');
    $this->travelTo(CarbonImmutable::parse('2026-11-02 13:00:00', 'UTC'));
    ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => false]])->assertOk();

    $at = fn (string $hm): string => collect(app(Replay::class)->at($this->f->kitchen->id, CarbonImmutable::parse("2026-11-02 {$hm}:00", 'UTC'))->counts)
        ->firstWhere('countId', $count)?->statusAt;
    expect($at('12:10'))->toBe('awaiting_cosign')->and($at('12:40'))->toBe('pending_approval');
});

it('leaves the production overseer alone: switching co-sign never touches requires_overseer, nor the reverse', function () {
    $this->f->kitchen->forceFill(['requires_overseer' => true])->save();

    ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => false]])->assertOk();
    expect(($this->kitchen)()->requires_overseer)->toBeTrue();
    ($this->rules)([['id' => $this->f->kitchen->id, 'cosign' => true]])->assertOk();
    $this->f->send('PATCH', "/api/v1/stations/{$this->f->kitchen->id}", $this->f->admin, ['requires_overseer' => false])->assertOk();

    expect(($this->kitchen)()->count_cosign)->toBeTrue()->and(($this->kitchen)()->requires_overseer)->toBeFalse();
});
