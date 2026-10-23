<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Contracts\Locks;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Events\CountSubmitted;
use App\Shared\Events\ShiftLocked;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * Step 3.4 (phase-3 plan section 4, "How a count works"; section 6 item 4): a count is locked and becomes a link of its place's hash chain
 * in the same transaction as its approval, whichever way it gets there: submitted without a difference, approved by a decider, or
 * co-signed without a difference. The shift locks when its last count locks. A repeat answers itself and adds no link.
 * Monday 2026-11-02; the kitchen keeps flour, sugar and salt in grams.
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
    $this->row = fn (?int $id = null) => StockCount::query()->findOrFail($id ?? $this->count);
    $this->same = function (): void {
        $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true])->assertOk();
    };
    $this->short = function (): void {
        $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
    };
    $this->verify = fn (): array => app(HashChain::class)->verify($this->place->id)->toArray();
});

it('locks a count that is submitted without a difference: the first link of the place', function () {
    ($this->same)();

    $response = $this->f->submit($this->f->chef, $this->count)->assertOk()
        ->assertJsonPath('status', 'locked')
        ->assertJsonPath('chain.chain_seq', 1)
        ->assertJsonPath('chain.previous_hash', str_repeat('0', 64));

    $row = ($this->row)();
    expect($row->status)->toBe(CountStatus::Locked)
        ->and($row->chain_seq)->toBe(1)
        ->and($row->payload_hash)->toBe($response->json('chain.payload_hash'))
        ->and($row->payload_hash)->toBe(hash('sha256', str_repeat('0', 64).$row->canonical_payload))
        ->and($row->locked_by_user_id)->toBe($this->f->chef->id, 'the person who handed it in locked it')
        ->and($row->locked_at?->toIso8601String())->toBe('2026-11-02T12:00:00+00:00')
        ->and($response->json('chain.locked_at'))->toBe('2026-11-02T12:00:00.000000+00:00')
        ->and(($this->verify)())->toMatchArray(['ok' => true, 'checked' => 1]);
    $payload = json_decode($row->canonical_payload, true);
    expect($payload['decision'])->toBeNull('nobody decided: there was nothing to decide')
        ->and($payload['lines'])->toHaveCount(2);
});

it('locks the shift with its only count, at once', function () {
    ($this->same)();

    $this->f->submit($this->f->chef, $this->count)->assertOk();

    $shift = ShiftInstance::query()->findOrFail($this->shift->id);
    expect($shift->status->value)->toBe('locked')
        ->and($shift->open_key)->toBeNull()
        ->and($shift->locked_at)->not->toBeNull();
});

it('locks a count when it is approved, in the same step, with the adjustment in the payload', function () {
    ($this->short)();
    $this->f->submit($this->f->chef, $this->count)->assertOk()->assertJsonPath('status', 'pending_approval');
    expect(($this->row)()->chain_seq)->toBeNull('a count waiting for a decision is not a link yet');

    $response = $this->f->approve($this->f->admin, $this->count, ['note' => 'Checked'])->assertOk()
        ->assertJsonPath('status', 'locked')
        ->assertJsonPath('decision', 'approved')
        ->assertJsonPath('chain.chain_seq', 1);

    $row = ($this->row)();
    $payload = json_decode($row->canonical_payload, true);
    $flour = collect($payload['lines'])->firstWhere('item_id', $this->flour->id);
    expect($row->locked_by_user_id)->toBe($this->f->admin->id)
        ->and($payload['decision'])->toBe('approved')
        ->and($payload['decision_note'])->toBe('Checked')
        ->and($payload['decided_by_user_id'])->toBe($this->f->admin->id)
        ->and($flour['adjustment_qty'])->toBe('-500.0000')
        ->and($flour['adjustment_movement_id'])->toBe(collect($response->json('lines'))->firstWhere('item.id', $this->flour->id)['adjustment_movement_id'])
        ->and($flour['reason_code'])->toBe('spoilage')
        ->and(($this->verify)()['ok'])->toBeTrue();
    expect(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('locked');
});

it('locks a count that a co-signature resolves without a difference', function () {
    $this->f->overseer($this->f->manager, $this->f->kitchen);
    DB::table('stations')->where('id', $this->f->kitchen->id)->update(['requires_overseer' => true]);
    ($this->same)();
    $this->f->submit($this->f->chef, $this->count)->assertOk()->assertJsonPath('status', 'awaiting_cosign');

    $this->f->cosign($this->f->manager, $this->count)->assertOk()
        ->assertJsonPath('status', 'locked')
        ->assertJsonPath('chain.chain_seq', 1);

    $row = ($this->row)();
    expect($row->locked_by_user_id)->toBe($this->f->manager->id)
        ->and(json_decode($row->canonical_payload, true)['cosigned_by_user_id'])->toBe($this->f->manager->id)
        ->and(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('locked');
});

it('makes the next count of the place the next link, and an opening count a link as well', function () {
    ($this->same)();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    $first = ($this->row)();

    $next = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();
    $opening = (int) $next->json('counts.0.id');
    $this->f->lines($this->f->chef, $opening, ['confirm_all' => true])->assertOk();
    $this->f->submit($this->f->chef, $opening)->assertOk()
        ->assertJsonPath('status', 'locked')
        ->assertJsonPath('chain.chain_seq', 2)
        ->assertJsonPath('chain.previous_hash', $first->payload_hash);

    expect(($this->verify)())->toMatchArray(['ok' => true, 'checked' => 2]);
});

it('announces ShiftLocked once, naming the links, when the last count locks', function () {
    Event::fake([ShiftLocked::class, CountSubmitted::class]);
    ($this->same)();

    $this->f->submit($this->f->chef, $this->count)->assertOk();

    Event::assertDispatchedTimes(ShiftLocked::class, 1);
    Event::assertDispatched(ShiftLocked::class, function (ShiftLocked $e): bool {
        $p = $e->payload();
        $row = ($this->row)();

        return $p['shift_id'] === $this->shift->id && $p['station_id'] === $this->f->kitchen->id && $p['station_name_en'] === $this->f->kitchen->name_en
            && $p['date'] === $this->shift->date->toDateString() && is_string($p['locked_at'])
            && $p['links'] === [['location_id' => $this->place->id, 'chain_seq' => 1, 'payload_hash' => $row->payload_hash]];
    });
    Event::assertDispatched(CountSubmitted::class, fn (CountSubmitted $e): bool => $e->payload()['status_after'] === 'locked');
});

it('does not announce ShiftLocked while a count of the shift still waits', function () {
    Event::fake([ShiftLocked::class]);
    ($this->short)();

    $this->f->submit($this->f->chef, $this->count)->assertOk();

    Event::assertNotDispatched(ShiftLocked::class);
});

it('answers a repeat of the approval with the locked count and adds no link and no audit row', function () {
    ($this->short)();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    $first = $this->f->approve($this->f->admin, $this->count)->assertOk();
    $audit = AuditLog::query()->count();

    $again = $this->f->approve($this->f->manager, $this->count)->assertOk();

    expect($again->json('chain.payload_hash'))->toBe($first->json('chain.payload_hash'))
        ->and(StockCount::query()->whereNotNull('chain_seq')->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($audit);
});

it('has the next opening count carry the locked closing, and previousClosing prefer locked rows that are not superseded', function () {
    ($this->short)();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    $this->f->approve($this->f->admin, $this->count)->assertOk();

    $previous = app(Locks::class)->previousClosing($this->place->id);
    expect($previous?->id)->toBe($this->count)
        ->and($previous?->status)->toBe(CountStatus::Locked);

    $opening = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();
    $line = collect($opening->json('counts.0.lines'))->firstWhere('item.id', $this->flour->id);
    expect($line['carried_qty'])->toBe('9500.0000');

    DB::table('stock_counts')->where('id', $this->count)->update(['superseded_by_id' => StockCount::query()->where('id', '!=', $this->count)->value('id'), 'superseded_at' => now()]);
    expect(app(Locks::class)->previousClosing($this->place->id))->toBeNull('a superseded closing is not carried');
});

it('rolls the lock back with the approval when the approval is refused', function () {
    ($this->short)();
    $this->f->submit($this->f->admin, $this->count)->assertOk();
    $this->f->approve($this->f->admin, $this->count)->assertStatus(409)->assertJsonPath('message_key', 'shifts.count.approve_self');

    expect(($this->row)()->chain_seq)->toBeNull()
        ->and(($this->row)()->status)->toBe(CountStatus::PendingApproval);
});
