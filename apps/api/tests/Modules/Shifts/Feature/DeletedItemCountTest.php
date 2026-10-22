<?php

declare(strict_types=1);

use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * Carry from step 3.3b: an item that is soft-deleted while a count still lists it cannot be locked by the Ledger, which used to surface
 * as an unmapped server error. Approving, and submitting, now answer a clean bilingual 409 shifts.count.item_unavailable naming the
 * items; nothing is written, and the count can still be rejected and counted again.
 */

beforeEach(function () {
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
    $this->fill = fn () => $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true, 'lines' => [
        ['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')],
        ['item_id' => $this->sugar->id, 'counted_qty' => '1900', 'reason_id' => $this->f->reasonId('spoilage')],
    ]])->assertOk();
    $this->adjustments = fn (): int => DB::table('stock_movements')->where('reason', 'count_adjustment')->count();
});

it('refuses to approve a count whose variance line is for a deleted item, with a bilingual 409 and nothing written', function () {
    ($this->fill)();
    $this->f->submit($this->f->chef, $this->count)->assertOk()->assertJsonPath('status', 'pending_approval');
    $this->flour->delete();

    $response = $this->f->approve($this->f->admin, $this->count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.item_unavailable')
        ->assertJsonPath('item_ids', [$this->flour->id]);

    expect($response->json('message_en'))->not->toBe('shifts.count.item_unavailable')
        ->and($response->json('message_am'))->toMatch('/\p{Ethiopic}/u')
        ->and(StockCount::query()->findOrFail($this->count)->status)->toBe(CountStatus::PendingApproval)
        ->and(($this->adjustments)())->toBe(0, 'not even the sugar line was written');
});

it('lets the approver reject that count and count again', function () {
    ($this->fill)();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    $this->flour->delete();

    $this->f->reject($this->f->admin, $this->count, ['note' => 'Count again'])->assertOk()->assertJsonPath('recount.status', 'draft');
});

it('refuses to submit a count that lists a deleted item with the same answer', function () {
    ($this->fill)();
    $this->sugar->delete();

    $this->f->submit($this->f->chef, $this->count)->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.item_unavailable')
        ->assertJsonPath('item_ids', [$this->sugar->id]);

    expect(StockCount::query()->findOrFail($this->count)->status)->toBe(CountStatus::Draft);
});
