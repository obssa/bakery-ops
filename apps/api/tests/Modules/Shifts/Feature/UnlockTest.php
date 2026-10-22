<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Contracts\Locks;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * POST /stock-counts/{count}/unlock (step 3.4; phase-3 plan section 4, "An unlock"): the admin (shifts.unlock) gives a locked count a
 * revision. The old row stays locked, visible and a link of the chain, with superseded_by_id pointing at the revision; the revision is a
 * new draft of the same shift, place and type, seq + 1, supersede_cause = unlock, whose lines carry the old counted figures (to be looked
 * at again). Submitted and approved like any count, it measures against the ledger, which the first approval made equal to the old counted
 * figure, so it writes only the correcting difference, and it becomes the next link when it locks.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->sugar = $this->f->ingredient();
    $this->f->stock($this->flour, $this->place, '10000');
    $this->f->stock($this->sugar, $this->place, '2000');
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->count = (int) $this->f->close($this->f->chef, $this->shift->id)->assertOk()->json('counts.0.id');
    // flour 500 short (approved by the admin and locked), sugar as expected
    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    $this->f->approve($this->f->admin, $this->count)->assertOk()->assertJsonPath('status', 'locked');
    $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00', 'UTC'));
    $this->unlock = fn (?int $id = null, array $body = ['reason' => 'The flour was counted twice'], $as = null) => $this->f->send('POST', '/api/v1/stock-counts/'.($id ?? $this->count).'/unlock', $as ?? $this->f->admin, $body);
    $this->onHand = fn ($item): string => app(Stock::class)->of($item, $this->place)->onHand->toString();
});

it('gives a locked count a revision and keeps the old row locked, superseded and visible', function () {
    $response = ($this->unlock)()->assertOk();

    $old = StockCount::query()->findOrFail($this->count);
    $revision = StockCount::query()->findOrFail($response->json('revision.id'));
    $response->assertJsonPath('unlocked.id', $this->count)
        ->assertJsonPath('unlocked.status', 'locked')
        ->assertJsonPath('unlocked.superseded_by_id', $revision->id)
        ->assertJsonPath('revision.status', 'draft')
        ->assertJsonPath('revision.seq', 2)
        ->assertJsonPath('revision.supersede_cause', 'unlock');
    expect($old->status)->toBe(CountStatus::Locked)
        ->and($old->superseded_by_id)->toBe($revision->id)
        ->and($old->superseded_at?->toIso8601String())->toBe('2026-11-03T09:00:00+00:00')
        ->and($old->chain_seq)->toBe(1)
        ->and($revision->shift_instance_id)->toBe($old->shift_instance_id)
        ->and($revision->location_id)->toBe($old->location_id)
        ->and($revision->type)->toBe($old->type)
        ->and($revision->unlock_reason)->toBe('The flour was counted twice')
        ->and($revision->chain_seq)->toBeNull()
        ->and(ShiftInstance::query()->findOrFail($old->shift_instance_id)->status->value)->toBe('locked', 'the shift stays locked');
    $audit = AuditLog::query()->where('action', 'count.unlock')->sole();
    expect($audit->actor_id)->toBe($this->f->admin->id)
        ->and($audit->context['reason'] ?? null)->toBe('The flour was counted twice');
});

it('prefills the revision with the old counted figures and reasons, unconfirmed, for the counter to look at again', function () {
    $response = ($this->unlock)()->assertOk();

    $lines = collect($response->json('revision.lines'))->keyBy('item.id');
    expect($lines)->toHaveCount(2)
        ->and($lines[$this->flour->id]['counted_qty'])->toBe('9500.0000')
        ->and($lines[$this->flour->id]['reason']['code'])->toBe('spoilage')
        ->and($lines[$this->flour->id]['confirmed'])->toBeFalse()
        ->and($lines[$this->sugar->id]['counted_qty'])->toBe('2000.0000')
        ->and($lines[$this->sugar->id]['confirmed'])->toBeFalse();
    $this->f->submit($this->f->chef, $response->json('revision.id'))->assertStatus(422)->assertJsonPath('message_key', 'shifts.count.incomplete');
});

it('writes only the correcting difference, and chains the revision as the next link while the old link still verifies', function () {
    $revision = (int) ($this->unlock)()->assertOk()->json('revision.id');
    $oldHash = StockCount::query()->findOrFail($this->count)->payload_hash;
    $oldPayload = StockCount::query()->findOrFail($this->count)->canonical_payload;
    $before = DB::table('stock_movements')->where('reason', 'count_adjustment')->count();

    // the right figure was 9400, not 9500: 100 g short of what the first approval made the ledger
    $this->f->lines($this->f->chef, $revision, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9400', 'reason_id' => $this->f->reasonId('miscount')]]])->assertOk();
    $submitted = $this->f->submit($this->f->chef, $revision)->assertOk()->assertJsonPath('status', 'pending_approval');
    $flour = collect($submitted->json('lines'))->firstWhere('item.id', $this->flour->id);
    expect($flour['expected_qty'])->toBe('9500.0000', 'the ledger the first approval left')
        ->and($flour['variance_qty'])->toBe('-100.0000');

    $this->f->approve($this->f->manager, $revision)->assertOk()
        ->assertJsonPath('status', 'locked')
        ->assertJsonPath('chain.chain_seq', 2)
        ->assertJsonPath('chain.previous_hash', $oldHash);

    expect(DB::table('stock_movements')->where('reason', 'count_adjustment')->count())->toBe($before + 1, 'one adjustment, for the flour only')
        ->and(DB::table('stock_movements')->where('reason', 'count_adjustment')->orderByDesc('id')->value('qty'))->toBe('-100.0000')
        ->and(($this->onHand)($this->flour))->toBe('9400.0000');
    $old = StockCount::query()->findOrFail($this->count);
    expect($old->payload_hash)->toBe($oldHash)
        ->and($old->canonical_payload)->toBe($oldPayload, 'the old link is untouched')
        ->and($old->status)->toBe(CountStatus::Locked);
    $result = app(HashChain::class)->verify($this->place->id)->toArray();
    expect($result)->toMatchArray(['ok' => true, 'checked' => 2, 'failures' => []]);
    $payload = json_decode(StockCount::query()->findOrFail($revision)->canonical_payload, true);
    expect($payload['supersedes_count_id'])->toBe($this->count)
        ->and($payload['seq'])->toBe(2);
});

it('chains a revision that changes nothing straight away: submitted equal to the ledger, it locks', function () {
    $revision = (int) ($this->unlock)()->assertOk()->json('revision.id');

    $this->f->lines($this->f->chef, $revision, ['confirm_all' => true])->assertOk();
    $this->f->submit($this->f->chef, $revision)->assertOk()
        ->assertJsonPath('status', 'locked')
        ->assertJsonPath('chain.chain_seq', 2);

    expect(app(HashChain::class)->verify($this->place->id)->toArray())->toMatchArray(['ok' => true, 'checked' => 2]);
});

it('carries the revision, not the unlocked count, once the revision is locked', function () {
    $revision = (int) ($this->unlock)()->assertOk()->json('revision.id');
    $this->f->lines($this->f->chef, $revision, ['confirm_all' => true])->assertOk();
    $this->f->submit($this->f->chef, $revision)->assertOk();

    expect(app(Locks::class)->previousClosing($this->place->id)?->id)->toBe($revision);
});

it('refuses a count that is not locked, one already unlocked, and a missing or too long reason', function () {
    $revision = (int) ($this->unlock)()->assertOk()->json('revision.id');

    ($this->unlock)($revision)->assertStatus(409)->assertJsonPath('message_key', 'shifts.count.not_locked')->assertJsonPath('status', 'draft');
    ($this->unlock)()->assertStatus(409)->assertJsonPath('message_key', 'shifts.count.already_superseded')->assertJsonPath('superseded_by_id', $revision);
    ($this->unlock)($this->count, [])->assertStatus(422);
    ($this->unlock)($this->count, ['reason' => str_repeat('x', 256)])->assertStatus(422);
    ($this->unlock)($this->count, ['reason' => '   '])->assertStatus(422);
    expect(StockCount::query()->where('shift_instance_id', $this->shift->id)->count())->toBe(2, 'nothing else was made');
});

it('is the admin only', function (string $who) {
    $user = $this->f->{$who};

    ($this->unlock)(null, ['reason' => 'Because'], $user)->assertForbidden();
    expect(StockCount::query()->findOrFail($this->count)->superseded_by_id)->toBeNull();
})->with(['manager', 'chef', 'cashier', 'waiter']);

it('answers a repeat with the same key as the first answer and makes one revision', function () {
    $key = idempotencyKey();
    loginAs($this->f->admin);

    $first = $this->withHeaders($key)->postJson("/api/v1/stock-counts/{$this->count}/unlock", ['reason' => 'Twice counted'])->assertOk();
    $second = $this->withHeaders($key)->postJson("/api/v1/stock-counts/{$this->count}/unlock", ['reason' => 'Twice counted'])->assertOk();

    expect($second->json('revision.id'))->toBe($first->json('revision.id'))
        ->and(StockCount::query()->where('supersede_cause', 'unlock')->count())->toBe(1);
});

it('does not find the count of another branch', function () {
    $other = Branch::factory()->create();
    $f2 = ShiftsFixture::build($other, $this);
    actInBranch($this->branch);
    $place = $f2->place($f2->kitchen);
    $foreign = StockCount::factory()->forShift(ShiftInstance::factory()->locked()->create(['branch_id' => $other->id, 'station_id' => $f2->kitchen->id, 'created_by_user_id' => $f2->chef->id]), $place->id)
        ->closing()->status(CountStatus::Locked)->create(['created_by_user_id' => $f2->chef->id]);

    ($this->unlock)($foreign->id)->assertNotFound();
});
