<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Shifts\Contracts\Counts;
use App\Modules\Shifts\Enums\CountDecision;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * POST /stock-counts/{count}/approve (step 3.3b; phase-3 plan section 4, "How a count works", section 10.4). Approval writes one
 * count_adjustment per line with a variance (counted minus expected, both signs) in one Ledger::appendMany run as the counted
 * shift, sets adjustment_movement_id on each line, and leaves the count `approved` (the lock with its hash is step 3.4). It is a
 * delta, not "set the stock to the counted figure": stock that moved after the snapshot survives. A delta that would take on_hand
 * below zero is refused whole (409 shifts.adjustment.insufficient), nothing written. A second approve writes nothing. The shift
 * moves to `locked` only when its last count is resolved.
 * Monday 2026-11-02 08:00 UTC; the kitchen keeps its stock in grams.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->sugar = $this->f->ingredient();
    $this->salt = $this->f->ingredient();
    $this->f->stock($this->flour, $this->place, '10000');
    $this->f->stock($this->sugar, $this->place, '2000');
    $this->f->stock($this->salt, $this->place, '300');
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->count = (int) $this->f->close($this->f->chef, $this->shift->id)->assertOk()->json('counts.0.id');
    // flour is 500 short, sugar 300 over, salt as expected
    $this->submitVariance = function (int $count, string $flour = '9500', string $sugar = '2300'): void {
        $this->f->lines($this->f->chef, $count, ['confirm_all' => true, 'lines' => [
            ['item_id' => $this->flour->id, 'counted_qty' => $flour, 'reason_id' => $this->f->reasonId('spoilage')],
            ['item_id' => $this->sugar->id, 'counted_qty' => $sugar, 'reason_id' => $this->f->reasonId('miscount')],
        ]])->assertOk();
        $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'pending_approval');
    };
    $this->onHand = fn ($item, $place = null): string => app(Stock::class)->of($item, $place ?? $this->place)->onHand->toString();
    $this->movements = fn () => DB::table('stock_movements')->where('reason', 'count_adjustment')->orderBy('id');
});

it('writes one count_adjustment per variance line, both signs, stamped with the counted shift', function () {
    ($this->submitVariance)($this->count);
    $before = DB::table('stock_movements')->count();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:30:00', 'UTC'));

    $response = $this->f->approve($this->f->admin, $this->count, ['note' => 'Checked the store room'])->assertOk();

    $rows = ($this->movements)()->get();
    expect($rows)->toHaveCount(2)
        ->and(DB::table('stock_movements')->count())->toBe($before + 2, 'nothing for the line that did not differ');
    $byItem = $rows->keyBy('item_id');
    expect($byItem[$this->flour->id]->qty)->toBe('-500.0000')
        ->and($byItem[$this->sugar->id]->qty)->toBe('300.0000');
    foreach ($rows as $row) {
        expect((int) $row->shift_instance_id)->toBe($this->shift->id, 'stamped with the counted shift')
            ->and((int) $row->station_id)->toBe($this->f->kitchen->id)
            ->and((int) $row->user_id)->toBe($this->f->admin->id, 'the approver wrote it')
            ->and($row->ref_type)->toBe('stock_count_line');
    }

    $response->assertJsonPath('status', 'locked')
        ->assertJsonPath('decision', 'approved')
        ->assertJsonPath('decided_by.id', $this->f->admin->id)
        ->assertJsonPath('decision_note', 'Checked the store room');
    $lines = collect($response->json('lines'))->keyBy('item.id');
    expect($lines[$this->flour->id]['adjustment_movement_id'])->toBe((int) $byItem[$this->flour->id]->id)
        ->and($lines[$this->flour->id]['reason_status'])->toBe('accepted')
        ->and($lines[$this->sugar->id]['adjustment_movement_id'])->toBe((int) $byItem[$this->sugar->id]->id)
        ->and($lines[$this->salt->id]['adjustment_movement_id'])->toBeNull()
        ->and($lines[$this->salt->id]['reason_status'])->toBe('none');
    $count = StockCount::query()->findOrFail($this->count);
    expect($count->status)->toBe(CountStatus::Locked)
        ->and($count->decision)->toBe(CountDecision::Approved)
        ->and($count->decided_by_user_id)->toBe($this->f->admin->id)
        ->and($count->decided_at?->toIso8601String())->toBe('2026-11-02T12:30:00+00:00')
        ->and($count->chain_seq)->toBe(1, 'approved and locked in one step (step 3.4)');
});

it('leaves the ledger equal to the counted figure when nothing else moved', function () {
    ($this->submitVariance)($this->count);

    $this->f->approve($this->f->admin, $this->count)->assertOk();

    expect(($this->onHand)($this->flour))->toBe('9500.0000')
        ->and(($this->onHand)($this->sugar))->toBe('2300.0000')
        ->and(($this->onHand)($this->salt))->toBe('300.0000');
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
});

it('leaves the ledger at the counted figure plus what moved since the snapshot (a delta, not a reset)', function () {
    ($this->submitVariance)($this->count);
    $this->f->stock($this->flour, $this->place, '700'); // a delivery lands after the snapshot
    $this->f->stock($this->sugar, $this->place, '-100', MovementReason::Waste);

    $this->f->approve($this->f->admin, $this->count)->assertOk();

    expect(($this->onHand)($this->flour))->toBe('10200.0000', 'counted 9500 plus the 700 that arrived')
        ->and(($this->onHand)($this->sugar))->toBe('2200.0000', 'counted 2300 less the 100 wasted');
});

it('answers a second approve with the count and writes nothing, not even an audit row', function () {
    ($this->submitVariance)($this->count);
    $first = $this->f->approve($this->f->admin, $this->count)->assertOk();
    $movements = DB::table('stock_movements')->count();
    $audit = AuditLog::query()->count();

    $again = $this->f->approve($this->f->admin, $this->count)->assertOk();
    $byTheOther = $this->f->approve($this->f->manager, $this->count)->assertOk();

    expect($again->json())->toBe($first->json())
        ->and($byTheOther->json())->toBe($first->json())
        ->and(DB::table('stock_movements')->count())->toBe($movements)
        ->and(AuditLog::query()->count())->toBe($audit)
        ->and(AuditLog::query()->where('action', 'count.approve')->count())->toBe(1)
        ->and(($this->onHand)($this->flour))->toBe('9500.0000');
});

it('records one audit row for the approval, by the approver, on the count', function () {
    ($this->submitVariance)($this->count);

    $this->f->approve($this->f->admin, $this->count)->assertOk();

    $row = AuditLog::query()->where('action', 'count.approve')->sole();
    expect($row->subject_id)->toBe($this->count)->and($row->actor_id)->toBe($this->f->admin->id);
});

it('refuses the whole approval when one adjustment would take on_hand below zero, and writes nothing', function () {
    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true, 'lines' => [
        ['item_id' => $this->sugar->id, 'counted_qty' => '2300', 'reason_id' => $this->f->reasonId('miscount')],
        ['item_id' => $this->flour->id, 'counted_qty' => '0', 'reason_id' => $this->f->reasonId('spoilage')],
    ]])->assertOk();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    $this->f->stock($this->flour, $this->place, '-9800', MovementReason::Waste); // stock left after the snapshot: 200 remain, the delta is -10000
    $movements = DB::table('stock_movements')->count();
    $audit = AuditLog::query()->count();

    $this->f->approve($this->f->admin, $this->count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.adjustment.insufficient')
        ->assertJsonPath('item_id', $this->flour->id)
        ->assertJsonPath('location_id', $this->place->id)
        ->assertJsonPath('on_hand', '200.0000')
        ->assertJsonPath('adjustment', '-10000.0000');

    expect(DB::table('stock_movements')->count())->toBe($movements, 'the positive sugar line was not written either')
        ->and(AuditLog::query()->count())->toBe($audit)
        ->and(($this->onHand)($this->sugar))->toBe('2000.0000')
        ->and(($this->onHand)($this->flour))->toBe('200.0000');
    $count = StockCount::query()->findOrFail($this->count);
    expect($count->status)->toBe(CountStatus::PendingApproval)
        ->and($count->decision)->toBeNull()
        ->and($count->lines()->whereNotNull('adjustment_movement_id')->count())->toBe(0)
        ->and($count->lines()->where('reason_status', 'pending')->count())->toBe(2)
        ->and(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('pending_approval');

    // reject and recount is the way out
    $this->f->reject($this->f->admin, $this->count, ['note' => 'Stock left, count again'])->assertOk()->assertJsonPath('status', 'rejected');
});

it('keeps the shift pending approval until its last count is resolved, then locks it (the counts stay approved)', function () {
    $bar = $this->f->place($this->f->kitchen, LocationType::Bar);
    $drink = $this->f->ingredient('ml');
    $this->f->stock($drink, $bar, '1000');
    // the closing of a shift with two places: close again on a fresh shift
    ShiftInstance::query()->whereKey($this->shift->id)->update(['status' => 'locked', 'open_key' => null, 'locked_at' => now()]);
    $other = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-11-03']);
    $close = $this->f->close($this->f->chef, $other->id)->assertOk();
    $ids = collect($close->json('counts'))->pluck('id', 'location.id');
    expect($ids)->toHaveCount(2);
    $this->f->lines($this->f->chef, $ids[$this->place->id], ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9000', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
    $this->f->lines($this->f->chef, $ids[$bar->id], ['confirm_all' => true, 'lines' => [['item_id' => $drink->id, 'counted_qty' => '1100', 'reason_id' => $this->f->reasonId('miscount')]]])->assertOk();
    $this->f->submit($this->f->chef, $ids[$this->place->id])->assertOk();
    $this->f->submit($this->f->chef, $ids[$bar->id])->assertOk();
    expect(ShiftInstance::query()->findOrFail($other->id)->status->value)->toBe('pending_approval');

    $this->f->approve($this->f->admin, $ids[$this->place->id])->assertOk();
    expect(ShiftInstance::query()->findOrFail($other->id)->status->value)->toBe('pending_approval');

    $this->f->approve($this->f->admin, $ids[$bar->id])->assertOk();
    $shift = ShiftInstance::query()->findOrFail($other->id);
    expect($shift->status->value)->toBe('locked')
        ->and($shift->open_key)->toBeNull()
        ->and($shift->locked_at)->not->toBeNull()
        ->and(StockCount::query()->whereIn('id', $ids->values()->all())->pluck('status')->map->value->unique()->all())->toBe(['locked']);
});

it('keeps the shift pending approval while an opening count of the same shift still waits', function () {
    $shift = $this->f->running($this->f->cakeRoom, $this->f->baker, ['date' => '2026-11-03'], state: 'pendingApproval');
    $place = $this->f->place($this->f->cakeRoom);
    $opening = StockCount::factory()->forShift($shift, $place->id)->opening()->status(CountStatus::PendingApproval)->create(['created_by_user_id' => $this->f->baker->id]);
    $closing = StockCount::factory()->forShift($shift, $place->id)->closing()->status(CountStatus::PendingApproval)->create(['created_by_user_id' => $this->f->baker->id]);

    $this->f->approve($this->f->admin, $closing->id)->assertOk();
    expect(ShiftInstance::query()->findOrFail($shift->id)->status->value)->toBe('pending_approval');

    $this->f->approve($this->f->admin, $opening->id)->assertOk();
    expect(ShiftInstance::query()->findOrFail($shift->id)->status->value)->toBe('locked');
});

it('refuses a count that is not waiting for a decision, naming its status', function (string $status) {
    StockCount::query()->whereKey($this->count)->update(['status' => $status]);

    $this->f->approve($this->f->admin, $this->count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.not_pending')
        ->assertJsonPath('status', $status);
})->with(['draft', 'awaiting_cosign']);

it('refuses a count that went straight to approved, because nobody has anything to decide', function () {
    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true])->assertOk();
    $this->f->submit($this->f->chef, $this->count)->assertOk()->assertJsonPath('status', 'locked');

    $this->f->approve($this->f->admin, $this->count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.not_pending')
        ->assertJsonPath('status', 'locked');
});

it('refuses a rejected count', function () {
    ($this->submitVariance)($this->count);
    $this->f->reject($this->f->admin, $this->count, ['note' => 'Again'])->assertOk();

    $this->f->approve($this->f->admin, $this->count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.not_pending')
        ->assertJsonPath('status', 'rejected');
});

it('is a call of the Counts contract too, run as the person acting', function () {
    ($this->submitVariance)($this->count);
    withActor($this->f->admin->id);

    $approved = app(Counts::class)->approve($this->count, 'by contract');

    expect($approved->status)->toBe(CountStatus::Locked)
        ->and($approved->decision_note)->toBe('by contract')
        ->and(($this->onHand)($this->flour))->toBe('9500.0000');
});

it('approves a count that has lines of more than one item with fractions exactly', function () {
    $oil = $this->f->ingredient('ml');
    $this->f->stock($oil, $this->place, '0.3');
    ShiftInstance::query()->whereKey($this->shift->id)->update(['status' => 'locked', 'open_key' => null, 'locked_at' => now()]);
    $other = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-11-03']);
    $count = (int) $this->f->close($this->f->chef, $other->id)->assertOk()->json('counts.0.id');
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true, 'lines' => [['item_id' => $oil->id, 'counted_qty' => '0.1', 'reason_id' => $this->f->reasonId('miscount')]]])->assertOk();
    $this->f->submit($this->f->chef, $count)->assertOk();

    $this->f->approve($this->f->admin, $count)->assertOk();

    expect(($this->onHand)($oil))->toBe('0.1000')
        ->and(($this->movements)()->where('item_id', $oil->id)->value('qty'))->toBe('-0.2000');
});
