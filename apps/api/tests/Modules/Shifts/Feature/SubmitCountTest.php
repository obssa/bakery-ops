<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Events\ShiftOpened;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * POST /stock-counts/{count}/submit (step 3.3a; phase-3 plan section 4, "How a count works", and section 10.8, rule R-A).
 * Submit locks the count row, then the shift row, then the balance rows of every line (and of every item the place holds), reads
 * the expected figure from those locked rows, takes the snapshot, works out counted minus expected in exact decimals, and refuses
 * a count that is incomplete, lacks a reason or a note, or is blocked by an unresolved count at the same place. It writes no
 * movement: a count with a variance waits in pending_approval, one without goes to approved (approval and the lock are 3.3b/3.4).
 * Monday 2026-11-02 08:00 UTC; the kitchen keeps its stock in grams.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->f->stock($this->flour, $this->place, '10000');
    $this->shift = null;
    $this->ensureShift = fn (): ShiftInstance => $this->shift ??= $this->f->running($this->f->kitchen, $this->f->chef);
    $this->closeAndGetCount = function (): int {
        ($this->ensureShift)();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));

        return (int) $this->f->close($this->f->chef, $this->shift->id)->assertOk()->json('counts.0.id');
    };
});

it('reads the expected figure at submit, not at the draft: stock that arrived while counting is in it', function () {
    $count = ($this->closeAndGetCount)();
    $this->f->stock($this->flour, $this->place, '500'); // a delivery lands while the chef counts (the flag is off in this module job)
    $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '10000', 'reason_id' => $this->f->reasonId('miscount')]]])->assertOk();
    $movements = DB::table('stock_movements')->count();

    $response = $this->f->submit($this->f->chef, $count)->assertOk();

    $response->assertJsonPath('id', $count)
        ->assertJsonPath('status', 'pending_approval')
        ->assertJsonPath('counted_by.id', $this->f->chef->id)
        ->assertJsonPath('lines.0.item.id', $this->flour->id)
        ->assertJsonPath('lines.0.expected_qty', '10500.0000')
        ->assertJsonPath('lines.0.counted_qty', '10000.0000')
        ->assertJsonPath('lines.0.variance_qty', '-500.0000')
        ->assertJsonPath('lines.0.reason_status', 'pending')
        ->assertJsonPath('lines.0.reason.code', 'miscount')
        ->assertJsonPath('lines.0.confirmed', true);
    expect($response->json('snapshot_at'))->not->toBeNull()
        ->and($response->json('submitted_at'))->toBe('2026-11-02T12:00:00+00:00')
        ->and(DB::table('stock_movements')->count())->toBe($movements, 'a submit writes no movement')
        ->and((int) StockCount::query()->findOrFail($count)->ledger_movement_id)->toBe((int) DB::table('stock_movements')->max('id'));
});

it('takes the snapshot under the ledger lock: expected equals the balance at snapshot_at', function () {
    $count = ($this->closeAndGetCount)();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:30', 'UTC'));
    $this->f->stock($this->flour, $this->place, '-250.5', MovementReason::Waste);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:01:00', 'UTC'));
    $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9749.5', 'confirm' => true]]])->assertOk();

    $response = $this->f->submit($this->f->chef, $count)->assertOk();

    $snapshot = CarbonImmutable::parse((string) $response->json('snapshot_at'));
    $line = StockCount::query()->findOrFail($count)->lines()->sole();
    expect($snapshot->format('Y-m-d H:i:s'))->toBe('2026-11-02 12:01:00')
        ->and($line->expected_qty?->toString())->toBe('9749.5000')
        ->and(app(Stock::class)->balanceAt($this->flour, $this->place, $snapshot)->onHand->toString())->toBe($line->expected_qty?->toString())
        ->and($line->variance_qty?->toString())->toBe('0.0000');
});

it('sends a count without a difference straight to locked, writes nothing, and locks the shift with its last count', function () {
    $count = ($this->closeAndGetCount)();
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertOk()
        ->assertJsonPath('status', 'locked')
        ->assertJsonPath('lines.0.variance_qty', '0.0000')
        ->assertJsonPath('lines.0.reason_status', 'none');

    $shift = ShiftInstance::query()->findOrFail($this->shift->id);
    expect($shift->status->value)->toBe('locked')
        ->and($shift->open_key)->toBeNull()
        ->and($shift->submitted_at?->toIso8601String())->toBe('2026-11-02T12:00:00+00:00')
        ->and($shift->locked_at)->not->toBeNull()
        ->and(DB::table('stock_movements')->where('reason', 'count_adjustment')->count())->toBe(0);
});

it('works out the variance in exact decimals', function (string $expected, string $counted, string $variance) {
    $item = $this->f->ingredient('ml');
    $this->f->stock($item, $this->place, $expected);
    $count = ($this->closeAndGetCount)();
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true, 'lines' => [['item_id' => $item->id, 'counted_qty' => $counted, 'reason_id' => $this->f->reasonId('miscount')]]])->assertOk();

    $response = $this->f->submit($this->f->chef, $count)->assertOk();

    $line = collect($response->json('lines'))->firstWhere('item.id', $item->id);
    expect($line['variance_qty'])->toBe($variance);
})->with([
    'a tenth against three tenths' => ['0.3', '0.1', '-0.2000'],
    'three tenths against a tenth' => ['0.1', '0.3', '0.2000'],
    'the smallest step' => ['12.3456', '12.3457', '0.0001'],
    'the largest the column holds' => ['9999999999.9999', '0', '-9999999999.9999'],
]);

it('refuses a count with an unconfirmed line, naming the items, and changes nothing', function () {
    $count = ($this->closeAndGetCount)();

    $this->f->submit($this->f->chef, $count)->assertStatus(422)
        ->assertJsonPath('message_key', 'shifts.count.incomplete')
        ->assertJsonPath('item_ids', [$this->flour->id]);

    $row = StockCount::query()->findOrFail($count);
    expect($row->status)->toBe(CountStatus::Draft)
        ->and($row->snapshot_at)->toBeNull()
        ->and($row->lines()->whereNotNull('expected_qty')->count())->toBe(0)
        ->and(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('counting');
});

it('refuses a count that misses an item the place now holds, until the counter adds it', function () {
    $count = ($this->closeAndGetCount)();
    $late = $this->f->ingredient();
    $this->f->stock($late, $this->place, '12');
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertStatus(422)
        ->assertJsonPath('message_key', 'shifts.count.incomplete')
        ->assertJsonPath('item_ids', [$late->id]);

    $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $late->id, 'counted_qty' => '12']]])->assertOk();
    $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'locked');
});

it('requires a reason for every line that differs, and only for those', function () {
    $other = $this->f->ingredient();
    $this->f->stock($other, $this->place, '40');
    $count = ($this->closeAndGetCount)();
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9000']]])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertStatus(422)
        ->assertJsonPath('message_key', 'shifts.count.reason_required')
        ->assertJsonPath('item_ids', [$this->flour->id]);

    $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $this->flour->id, 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
    $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'pending_approval');
});

it('requires a note when the reason says so', function () {
    $count = ($this->closeAndGetCount)();
    $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9000', 'reason_id' => $this->f->reasonId('other')]]])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertStatus(422)
        ->assertJsonPath('message_key', 'shifts.count.note_required')
        ->assertJsonPath('item_ids', [$this->flour->id]);

    $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $this->flour->id, 'reason_note' => 'A sack split in the store room']]])->assertOk();
    $this->f->submit($this->f->chef, $count)->assertOk()
        ->assertJsonPath('lines.0.note', 'A sack split in the store room')
        ->assertJsonPath('lines.0.reason.code', 'other');
});

it('refuses a second unresolved count at the same place (rule R-A) and names the one in the way', function (CountStatus $blocking) {
    $count = ($this->closeAndGetCount)();
    $earlier = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-11-01'], state: 'locked');
    $inTheWay = StockCount::factory()->forShift($earlier, $this->place->id)->closing()->status($blocking)->create();
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.blocked_by_pending')
        ->assertJsonPath('count_id', $inTheWay->id);
    expect(StockCount::query()->findOrFail($count)->status)->toBe(CountStatus::Draft);

    // once that one is resolved the way is clear
    $inTheWay->forceFill(['status' => CountStatus::Approved])->save();
    $this->f->submit($this->f->chef, $count)->assertOk();
})->with([CountStatus::AwaitingCosign, CountStatus::PendingApproval]);

it('does not block on an unresolved count at another place', function () {
    $count = ($this->closeAndGetCount)();
    $bar = $this->f->place($this->f->kitchen, LocationType::Bar);
    $earlier = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-11-01'], state: 'locked');
    StockCount::factory()->forShift($earlier, $bar->id)->closing()->status(CountStatus::PendingApproval)->create();
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertOk();
});

it('answers a repeat of the submit with the count and writes nothing more', function () {
    $count = ($this->closeAndGetCount)();
    $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9000', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
    $first = $this->f->submit($this->f->chef, $count)->assertOk();
    $audit = AuditLog::query()->count();

    $again = $this->f->submit($this->f->chef, $count)->assertOk();

    expect($again->json())->toBe($first->json())
        ->and(AuditLog::query()->count())->toBe($audit)
        ->and(AuditLog::query()->where('action', 'count.submit')->count())->toBe(1);
});

it('records one audit row for the submit, by the person who counted', function () {
    $count = ($this->closeAndGetCount)();
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertOk();

    $row = AuditLog::query()->where('action', 'count.submit')->sole();
    expect($row->subject_id)->toBe($count)->and($row->actor_id)->toBe($this->f->chef->id);
});

it('refuses a rejected count as not a draft', function () {
    $count = ($this->closeAndGetCount)();
    StockCount::query()->whereKey($count)->update(['status' => 'rejected', 'decision' => 'rejected', 'decided_at' => now(), 'decided_by_user_id' => $this->f->admin->id]);

    $this->f->submit($this->f->chef, $count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.not_draft')
        ->assertJsonPath('status', 'rejected');
});

it('refuses to submit a count whose shift is not taking counts, naming the status', function () {
    $count = ($this->closeAndGetCount)();
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();
    ShiftInstance::query()->whereKey($this->shift->id)->update(['status' => 'pending_approval', 'open_key' => null]);

    $this->f->submit($this->f->chef, $count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.shift_not_ready')
        ->assertJsonPath('status', 'pending_approval');
});

it('lets only someone who counts at the station submit: the admin may, a cashier and a waiter may not', function () {
    $count = ($this->closeAndGetCount)();
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();

    $this->f->submit($this->f->cashier, $count)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    $this->f->submit($this->f->waiter, $count)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    $this->f->submit($this->f->admin, $count)->assertOk()->assertJsonPath('counted_by.id', $this->f->admin->id);
});

it('does not see another branch\'s count', function () {
    $count = ($this->closeAndGetCount)();
    $otherBranch = Branch::factory()->create();
    $foreign = makeUser($otherBranch, 'admin');

    $this->f->submit($foreign, $count)->assertNotFound();
});

it('sends the count to a co-signer first at a station that requires an overseer', function () {
    $this->f->kitchen->forceFill(['requires_overseer' => true])->save();
    $count = ($this->closeAndGetCount)();
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'awaiting_cosign');
    expect(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('pending_approval');
});

it('allows a count below what is reserved and lists the lines at risk in the answer', function () {
    DB::table('stock_balances')->where('item_id', $this->flour->id)->where('location_id', $this->place->id)->update(['reserved' => '8000']);
    $count = ($this->closeAndGetCount)();
    $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '7000', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertOk()
        ->assertJsonPath('status', 'pending_approval')
        ->assertJsonPath('below_reserved.0.item_id', $this->flour->id)
        ->assertJsonPath('below_reserved.0.counted_qty', '7000.0000')
        ->assertJsonPath('below_reserved.0.reserved_qty', '8000.0000');
});

it('opens the shift when its opening count is submitted, counted, with the submitter as opener, and announces it', function () {
    Event::fake([ShiftOpened::class]);
    $start = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();
    $shiftId = $start->json('shift.id');
    $count = $start->json('counts.0.id');
    $start->assertJsonPath('shift.status', 'opening')->assertJsonPath('shift.opening_mode', null);
    Event::assertNotDispatched(ShiftOpened::class);
    $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'locked');

    $shift = ShiftInstance::query()->findOrFail($shiftId);
    expect($shift->status->value)->toBe('open')
        ->and($shift->open_key)->toBe(1)
        ->and($shift->opening_mode?->value)->toBe('counted')
        ->and($shift->opened_by_user_id)->toBe($this->f->chef->id)
        ->and($shift->opened_at)->not->toBeNull();
    Event::assertDispatched(ShiftOpened::class, fn (ShiftOpened $e): bool => $e->payload()['shift_id'] === $shiftId && $e->payload()['opening_mode'] === 'counted');
});

it('opens the shift on submit even when the opening count has a difference: the difference waits for approval', function () {
    $start = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();
    $count = $start->json('counts.0.id');
    $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9900', 'reason_id' => $this->f->reasonId('miscount')]]])->assertOk();

    $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'pending_approval');

    expect(ShiftInstance::query()->findOrFail($start->json('shift.id'))->status->value)->toBe('open');
});

it('opens the shift only when the opening count of every place is in', function () {
    $bar = $this->f->place($this->f->kitchen, LocationType::Bar);
    $start = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();
    $ids = collect($start->json('counts'))->pluck('id', 'location.id');
    expect($ids->keys()->all())->toBe([$this->place->id, $bar->id]);
    $shiftId = $start->json('shift.id');
    $this->f->lines($this->f->chef, $ids[$this->place->id], ['confirm_all' => true])->assertOk();

    $this->f->submit($this->f->chef, $ids[$this->place->id])->assertOk();
    expect(ShiftInstance::query()->findOrFail($shiftId)->status->value)->toBe('opening');

    $this->f->submit($this->f->chef, $ids[$bar->id])->assertOk();
    expect(ShiftInstance::query()->findOrFail($shiftId)->status->value)->toBe('open');
});

it('moves the shift on only when the closing count of every place is in (here nothing differs, so it locks)', function () {
    $bar = $this->f->place($this->f->kitchen, LocationType::Bar);
    $close = $this->f->close($this->f->chef, ($this->ensureShift)()->id)->assertOk();
    $ids = collect($close->json('counts'))->pluck('id', 'location.id');
    foreach ($ids as $id) {
        $this->f->lines($this->f->chef, $id, ['confirm_all' => true])->assertOk();
    }

    $this->f->submit($this->f->chef, $ids[$this->place->id])->assertOk();
    expect(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('counting');

    $this->f->submit($this->f->chef, $ids[$bar->id])->assertOk();
    expect(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('locked');
});
