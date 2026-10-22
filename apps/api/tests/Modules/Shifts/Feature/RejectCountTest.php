<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
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
 * POST /stock-counts/{count}/reject (step 3.3b; phase-3 plan section 4). The note is required. The count becomes `rejected`, a
 * recount draft (seq + 1, supersede_cause recount) is made from it with the counted values, reasons and notes prefilled and the
 * lines to look at again left unconfirmed (line_ids, default every line with a variance); both attempts are kept, the first
 * pointing at the second (superseded_by_id). The shift goes back to `counting` (or stays as it is when its station is held by the
 * next shift, or when the count was an opening one). Nothing is written to the ledger. A repeat answers 200 and writes nothing.
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
    $this->submitVariance = function (int $count): void {
        $this->f->lines($this->f->chef, $count, ['confirm_all' => true, 'lines' => [
            ['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage'), 'reason_note' => 'Mice'],
            ['item_id' => $this->sugar->id, 'counted_qty' => '2300', 'reason_id' => $this->f->reasonId('miscount')],
        ]])->assertOk();
        $this->f->submit($this->f->chef, $count)->assertOk()->assertJsonPath('status', 'pending_approval');
    };
});

it('rejects the count and hands back a prefilled recount draft; both attempts are kept and nothing is written to the ledger', function () {
    ($this->submitVariance)($this->count);
    $movements = DB::table('stock_movements')->count();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:30:00', 'UTC'));

    $response = $this->f->reject($this->f->admin, $this->count, ['note' => 'Flour looks wrong, check the second shelf'])->assertOk();

    $response->assertJsonPath('id', $this->count)
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('decision', 'rejected')
        ->assertJsonPath('decided_by.id', $this->f->admin->id)
        ->assertJsonPath('decision_note', 'Flour looks wrong, check the second shelf')
        ->assertJsonPath('recount.status', 'draft')
        ->assertJsonPath('recount.seq', 2)
        ->assertJsonPath('recount.type', 'closing')
        ->assertJsonPath('recount.supersede_cause', 'recount')
        ->assertJsonPath('recount.shift_id', $this->shift->id)
        ->assertJsonPath('recount.location.id', $this->place->id)
        ->assertJsonPath('recount.counted_by', null);
    $recountId = (int) $response->json('recount.id');
    $response->assertJsonPath('superseded_by_id', $recountId);

    $first = StockCount::query()->findOrFail($this->count);
    $second = StockCount::query()->findOrFail($recountId);
    expect($first->status)->toBe(CountStatus::Rejected)
        ->and($first->decision)->toBe(CountDecision::Rejected)
        ->and($first->superseded_by_id)->toBe($recountId)
        ->and($first->decided_at?->toIso8601String())->toBe('2026-11-02T12:30:00+00:00')
        ->and($second->status)->toBe(CountStatus::Draft)
        ->and($second->seq)->toBe(2)
        ->and($second->supersede_cause?->value)->toBe('recount')
        ->and(StockCount::query()->where('shift_instance_id', $this->shift->id)->count())->toBe(2)
        ->and(DB::table('stock_movements')->count())->toBe($movements, 'a rejection writes nothing to the ledger')
        ->and($first->lines()->where('reason_status', 'rejected')->count())->toBe(2, 'the reasons given were not accepted')
        ->and($first->lines()->where('reason_status', 'none')->count())->toBe(1);

    $lines = collect($response->json('recount.lines'))->keyBy('item.id');
    expect($lines[$this->flour->id]['counted_qty'])->toBe('9500.0000')
        ->and($lines[$this->flour->id]['confirmed'])->toBeFalse('a line with a variance must be looked at again')
        ->and($lines[$this->flour->id]['reason']['code'])->toBe('spoilage')
        ->and($lines[$this->flour->id]['note'])->toBe('Mice')
        ->and($lines[$this->sugar->id]['counted_qty'])->toBe('2300.0000')
        ->and($lines[$this->sugar->id]['confirmed'])->toBeFalse()
        ->and($lines[$this->salt->id]['counted_qty'])->toBe('300.0000')
        ->and($lines[$this->salt->id]['confirmed'])->toBeTrue('a line without a variance stays confirmed')
        ->and($lines[$this->flour->id]['reason_status'])->toBe('none');
});

it('leaves unconfirmed only the lines named in line_ids, and refuses a line that is not on the count', function () {
    ($this->submitVariance)($this->count);
    $flourLine = (int) DB::table('stock_count_lines')->where('count_id', $this->count)->where('item_id', $this->flour->id)->value('id');
    $foreign = (int) DB::table('stock_count_lines')->where('count_id', '!=', $this->count)->max('id') + 1000;

    $this->f->reject($this->f->admin, $this->count, ['note' => 'Again', 'line_ids' => [$foreign]])->assertStatus(422)
        ->assertJsonPath('message_key', 'validation.failed');
    expect(StockCount::query()->findOrFail($this->count)->status)->toBe(CountStatus::PendingApproval);

    $response = $this->f->reject($this->f->admin, $this->count, ['note' => 'Only the flour', 'line_ids' => [$flourLine]])->assertOk();

    $lines = collect($response->json('recount.lines'))->keyBy('item.id');
    expect($lines[$this->flour->id]['confirmed'])->toBeFalse()
        ->and($lines[$this->sugar->id]['confirmed'])->toBeTrue()
        ->and($lines[$this->salt->id]['confirmed'])->toBeTrue();
});

it('requires a note', function (array $body) {
    ($this->submitVariance)($this->count);

    $this->f->reject($this->f->admin, $this->count, $body)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');

    expect(StockCount::query()->findOrFail($this->count)->status)->toBe(CountStatus::PendingApproval)
        ->and(StockCount::query()->count())->toBe(1);
})->with([[[]], [['note' => '']], [['note' => '   ']]]);

it('sends the shift back to counting and holds its station again', function () {
    ($this->submitVariance)($this->count);
    expect(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('pending_approval');

    $this->f->reject($this->f->admin, $this->count, ['note' => 'Again'])->assertOk();

    $shift = ShiftInstance::query()->findOrFail($this->shift->id);
    expect($shift->status->value)->toBe('counting')
        ->and($shift->open_key)->toBe(1)
        ->and($shift->submitted_at)->toBeNull();
});

it('lets the recount be completed, submitted and approved: the delta is against the new expected figure', function () {
    ($this->submitVariance)($this->count);
    $rejected = $this->f->reject($this->f->admin, $this->count, ['note' => 'Again'])->assertOk();
    $recount = (int) $rejected->json('recount.id');
    $this->f->stock($this->flour, $this->place, '250'); // a delivery lands while they recount

    $this->f->submit($this->f->chef, $recount)->assertStatus(422)->assertJsonPath('message_key', 'shifts.count.incomplete');
    $this->f->lines($this->f->chef, $recount, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9700']]])->assertOk();
    $this->f->submit($this->f->chef, $recount)->assertOk()
        ->assertJsonPath('status', 'pending_approval')
        ->assertJsonPath('seq', 2);
    expect(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('pending_approval');

    $this->f->approve($this->f->admin, $recount)->assertOk()
        ->assertJsonPath('status', 'locked');

    $flour = DB::table('stock_movements')->where('reason', 'count_adjustment')->where('item_id', $this->flour->id)->sole();
    expect($flour->qty)->toBe('-550.0000', 'expected 10250, counted 9700')
        ->and(DB::table('stock_movements')->where('reason', 'count_adjustment')->count())->toBe(2)
        ->and(StockCount::query()->findOrFail($this->count)->status)->toBe(CountStatus::Rejected)
        ->and(ShiftInstance::query()->findOrFail($this->shift->id)->status->value)->toBe('locked');
});

it('answers a repeat with the same recount and writes nothing more', function () {
    ($this->submitVariance)($this->count);
    $first = $this->f->reject($this->f->admin, $this->count, ['note' => 'Again'])->assertOk();
    $audit = AuditLog::query()->count();

    $again = $this->f->reject($this->f->manager, $this->count, ['note' => 'Something else'])->assertOk();

    expect($again->json('recount.id'))->toBe($first->json('recount.id'))
        ->and($again->json('decision_note'))->toBe('Again')
        ->and(StockCount::query()->count())->toBe(2)
        ->and(AuditLog::query()->count())->toBe($audit)
        ->and(AuditLog::query()->where('action', 'count.reject')->count())->toBe(1);
});

it('refuses a count that is not waiting for a decision, naming its status', function (string $status) {
    StockCount::query()->whereKey($this->count)->update(['status' => $status]);

    $this->f->reject($this->f->admin, $this->count, ['note' => 'No'])->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.not_pending')
        ->assertJsonPath('status', $status);
    expect(StockCount::query()->count())->toBe(1);
})->with(['draft', 'approved']);

it('rejects a count that waits for a co-signature too', function () {
    $this->f->kitchen->forceFill(['requires_overseer' => true])->save();
    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
    $this->f->submit($this->f->chef, $this->count)->assertOk()->assertJsonPath('status', 'awaiting_cosign');

    $this->f->reject($this->f->admin, $this->count, ['note' => 'Count again'])->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('recount.status', 'draft');
});

it('keeps the shift pending approval when the next shift already holds the station, and still takes the recount', function () {
    ($this->submitVariance)($this->count);
    $next = $this->f->start($this->f->chef, $this->f->kitchen, ['carry' => true])->assertCreated();
    $next->assertJsonPath('shift.status', 'open');

    $rejected = $this->f->reject($this->f->admin, $this->count, ['note' => 'Again'])->assertOk();

    $old = ShiftInstance::query()->findOrFail($this->shift->id);
    expect($old->status->value)->toBe('pending_approval')
        ->and($old->open_key)->toBeNull();
    $recount = (int) $rejected->json('recount.id');
    $this->f->lines($this->f->chef, $recount, ['confirm_all' => true])->assertOk();
    $this->f->submit($this->f->chef, $recount)->assertOk();
    expect($old->refresh()->status->value)->toBe('pending_approval');
    $this->f->approve($this->f->admin, $recount)->assertOk();
    expect($old->refresh()->status->value)->toBe('locked');
});

it('rejects an opening count: the shift stays as it is and the recount can be submitted', function () {
    ShiftInstance::query()->whereKey($this->shift->id)->update(['status' => 'locked', 'open_key' => null, 'locked_at' => now()]);
    $start = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();
    $shiftId = $start->json('shift.id');
    $opening = $start->json('counts.0.id');
    $this->f->lines($this->f->chef, $opening, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9900', 'reason_id' => $this->f->reasonId('miscount')]]])->assertOk();
    $this->f->submit($this->f->chef, $opening)->assertOk()->assertJsonPath('status', 'pending_approval');
    expect(ShiftInstance::query()->findOrFail($shiftId)->status->value)->toBe('open');

    $rejected = $this->f->reject($this->f->admin, $opening, ['note' => 'Count the flour again'])->assertOk();

    $rejected->assertJsonPath('recount.type', 'opening')->assertJsonPath('recount.seq', 2);
    expect(ShiftInstance::query()->findOrFail($shiftId)->status->value)->toBe('open');
    $recount = (int) $rejected->json('recount.id');
    $this->f->lines($this->f->chef, $recount, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '10000']]])->assertOk();
    $this->f->submit($this->f->chef, $recount)->assertOk()->assertJsonPath('status', 'locked');
    expect(ShiftInstance::query()->findOrFail($shiftId)->status->value)->toBe('open');
});

it('is a call of the Counts contract too', function () {
    ($this->submitVariance)($this->count);
    withActor($this->f->admin->id);

    $recount = app(Counts::class)->reject($this->count, 'by contract');

    expect($recount->status)->toBe(CountStatus::Draft)
        ->and($recount->seq)->toBe(2)
        ->and(StockCount::query()->findOrFail($this->count)->decision_note)->toBe('by contract');
});
