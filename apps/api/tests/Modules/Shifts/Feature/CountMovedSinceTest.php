<?php

declare(strict_types=1);

use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * `moved_since` on each line of GET /stock-counts/{count} (step 3.3b; phase-3 plan section 4): what moved at the place between the
 * count's snapshot and now, as a signed decimal string, so the approver sees stock that arrived or left after the counter finished.
 * A closing count has none until it is handed in (null); once handed in it is the net change of movements after the snapshot,
 * without the count's own adjustments; a rejected or locked count is history (null). An opening count that is still a draft shows
 * the net movements since the previous closing of the place (what separates carried from expected).
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
    $this->read = fn (int $count, $as = null) => $this->f->send('GET', "/api/v1/stock-counts/{$count}", $as ?? $this->f->admin)->assertOk();
    $this->movedOf = fn ($response, $item): ?string => collect($response->json('lines'))->firstWhere('item.id', $item->id)['moved_since'];
});

it('is null on a closing draft', function () {
    $response = ($this->read)($this->count);

    expect(($this->movedOf)($response, $this->flour))->toBeNull();
});

it('shows zero right after the hand-in and the net change after it, per item', function () {
    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
    $this->f->submit($this->f->chef, $this->count)->assertOk();

    $response = ($this->read)($this->count);
    expect(($this->movedOf)($response, $this->flour))->toBe('0.0000')
        ->and(($this->movedOf)($response, $this->sugar))->toBe('0.0000');

    $this->f->stock($this->flour, $this->place, '700');
    $this->f->stock($this->flour, $this->place, '-200', MovementReason::Waste);
    $this->f->stock($this->sugar, $this->place, '-50', MovementReason::Waste);

    $response = ($this->read)($this->count);
    expect(($this->movedOf)($response, $this->flour))->toBe('500.0000')
        ->and(($this->movedOf)($response, $this->sugar))->toBe('-50.0000');
});

it('does not count the approval\'s own adjustments as movement since the count', function () {
    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    $this->f->stock($this->flour, $this->place, '700');
    $this->f->approve($this->f->admin, $this->count)->assertOk();

    $response = ($this->read)($this->count);

    expect(($this->movedOf)($response, $this->flour))->toBe('700.0000');
});

it('is null on a rejected count and shows the recount draft no movement either', function () {
    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    $recount = (int) $this->f->reject($this->f->admin, $this->count, ['note' => 'Again'])->assertOk()->json('recount.id');
    $this->f->stock($this->flour, $this->place, '700');

    expect(($this->movedOf)(($this->read)($this->count), $this->flour))->toBeNull()
        ->and(($this->movedOf)(($this->read)($recount), $this->flour))->toBeNull();
});

it('shows an opening draft the net movements since the previous closing', function () {
    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true])->assertOk();
    $this->f->submit($this->f->chef, $this->count)->assertOk()->assertJsonPath('status', 'approved');
    $this->f->stock($this->flour, $this->place, '400'); // arrives between the shifts
    $this->f->stock($this->sugar, $this->place, '-30', MovementReason::Waste);
    $start = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();
    $opening = (int) $start->json('counts.0.id');

    $response = ($this->read)($opening);

    expect(($this->movedOf)($response, $this->flour))->toBe('400.0000')
        ->and(($this->movedOf)($response, $this->sugar))->toBe('-30.0000')
        ->and(collect($response->json('lines'))->firstWhere('item.id', $this->flour->id)['carried_qty'])->toBe('10000.0000');
});

it('moves the ETag with the ledger while the count is undecided', function () {
    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true, 'lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9500', 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    $first = $this->f->send('GET', "/api/v1/stock-counts/{$this->count}", $this->f->admin)->assertOk();
    $etag = $first->headers->get('ETag');
    $this->f->send('GET', "/api/v1/stock-counts/{$this->count}", $this->f->admin, [], ['If-None-Match' => $etag])->assertStatus(304);

    $this->f->stock($this->flour, $this->place, '700');

    $second = $this->f->send('GET', "/api/v1/stock-counts/{$this->count}", $this->f->admin, [], ['If-None-Match' => $etag])->assertOk();
    expect(($this->movedOf)($second, $this->flour))->toBe('700.0000');
});
