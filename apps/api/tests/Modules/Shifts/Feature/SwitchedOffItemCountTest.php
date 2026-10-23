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
 * Step 3.7e (from the live run after the 3.7d merge): a count is what is on the shelf. An ingredient that was switched off while it
 * still has stock at the place is countable: the draft lists it, PUT /stock-counts/{count}/lines accepts it when it is added later
 * (it used to answer 422 shifts.count.item_unknown, while the submit still required it, so the station could never close), and the
 * submit asks for it. A switched-off item with no stock, a deleted item and a made-to-order product stay uncountable. Nothing is
 * written to the ledger by any of this.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->f->stock($this->flour, $this->place, '10000');
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->off = fn ($item) => $item->forceFill(['is_active' => false])->save();
});

it('lists a switched-off ingredient that still has stock on the closing draft and requires it at submit', function () {
    $sugar = $this->f->ingredient();
    $this->f->stock($sugar, $this->place, '2000');
    ($this->off)($sugar);

    $count = (int) $this->f->close($this->f->chef, $this->shift->id)->assertOk()->json('counts.0.id');

    expect(DB::table('stock_count_lines')->where('count_id', $count)->where('item_id', $sugar->id)->exists())->toBeTrue();
    $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '10000']]])->assertOk();
    $this->f->submit($this->f->chef, $count)->assertStatus(422)->assertJsonPath('message_key', 'shifts.count.incomplete');

    $this->f->lines($this->f->chef, $count, ['confirm_all' => true])->assertOk();
    $this->f->submit($this->f->chef, $count)->assertOk();
    expect(StockCount::query()->findOrFail($count)->status)->not->toBe(CountStatus::Draft);
});

it('accepts a switched-off ingredient with stock that is added to a draft later', function () {
    $count = (int) $this->f->close($this->f->chef, $this->shift->id)->assertOk()->json('counts.0.id');
    $sugar = $this->f->ingredient();
    $this->f->stock($sugar, $this->place, '2000');
    ($this->off)($sugar);

    $this->f->lines($this->f->chef, $count, ['confirm_all' => true, 'lines' => [['item_id' => $sugar->id, 'counted_qty' => '2000']]])->assertOk();

    expect(DB::table('stock_count_lines')->where('count_id', $count)->where('item_id', $sugar->id)->value('counted_qty'))->toBe('2000.0000');
    $this->f->submit($this->f->chef, $count)->assertOk();
    expect(DB::table('stock_movements')->where('reason', 'count_adjustment')->count())->toBe(0);
});

it('still refuses a switched-off item with no stock here, a deleted item with stock, and a made-to-order product', function () {
    $count = (int) $this->f->close($this->f->chef, $this->shift->id)->assertOk()->json('counts.0.id');
    $idle = $this->f->ingredient();
    ($this->off)($idle);
    $gone = $this->f->ingredient();
    $this->f->stock($gone, $this->place, '500');
    $gone->delete();

    foreach ([$idle, $gone] as $item) {
        $this->f->lines($this->f->chef, $count, ['lines' => [['item_id' => $item->id, 'counted_qty' => '1']]])
            ->assertStatus(422)->assertJsonPath('message_key', 'shifts.count.item_unknown');
    }
});
