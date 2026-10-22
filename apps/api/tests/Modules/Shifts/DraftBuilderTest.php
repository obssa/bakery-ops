<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Shifts\Contracts\Counts;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Shared\Models\Branch;
use Ramsey\Uuid\Uuid;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * The draft builder (phase-3 plan section 4, "How a count works"; section 10.8, "Count scope"): the lines of a draft are the items
 * with a non-zero on_hand at the place, the items of the previous count of that place (an item counted to zero stays visible
 * until a count after it leaves it at zero), and nothing else; one draft per stock place of the station and type, never the
 * cash drawer; asking again answers the same rows. The client uuid is derived, so a retried start or close finds the same row.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef, state: 'counting');
    $this->place = $this->f->place($this->f->kitchen);
    $this->counts = fn (): Counts => app(Counts::class);
});

/** @return array<int, string> item id => the line's carried_qty or null, ascending by item */
function draftLines(StockCount $count): array
{
    return $count->lines()->orderBy('item_id')->get()->mapWithKeys(fn (StockCountLine $l): array => [$l->item_id => $l->carried_qty?->toString()])->all();
}

it('drafts one closing count per stock place of the station, with the items that have stock there', function () {
    $flour = $this->f->ingredient();
    $sugar = $this->f->ingredient();
    $empty = $this->f->ingredient();
    $this->f->stock($flour, $this->place, '10000');
    $this->f->stock($sugar, $this->place, '250.5');
    $this->f->stock($empty, $this->place, '5');
    $this->f->stock($empty, $this->place, '-5', MovementReason::Waste);

    $drafts = ($this->counts)()->draftsFor($this->shift, CountType::Closing);

    expect($drafts)->toHaveCount(1);
    $count = $drafts[0];
    expect($count->status)->toBe(CountStatus::Draft)
        ->and($count->type)->toBe(CountType::Closing)
        ->and($count->seq)->toBe(1)
        ->and($count->location_id)->toBe($this->place->id)
        ->and($count->shift_instance_id)->toBe($this->shift->id)
        ->and($count->branch_id)->toBe($this->branch->id)
        ->and($count->counted_by_user_id)->toBeNull()
        ->and($count->snapshot_at)->toBeNull()
        ->and($count->created_by_user_id)->toBe($this->f->admin->id)
        ->and(array_keys(draftLines($count)))->toBe([$flour->id, $sugar->id])
        ->and($count->lines()->whereNotNull('counted_qty')->count())->toBe(0)
        ->and($count->lines()->whereNotNull('confirmed_at')->count())->toBe(0)
        ->and($count->lines()->whereNotNull('expected_qty')->count())->toBe(0);
});

it('makes a count for each stock place of the station and none for its cash drawer or another station\'s places', function () {
    $bar = $this->f->place($this->f->kitchen, LocationType::Bar);
    $this->f->place($this->f->kitchen, LocationType::CashDrawer);
    $this->f->place($this->f->till);

    $drafts = ($this->counts)()->draftsFor($this->shift, CountType::Opening);

    expect(array_map(fn (StockCount $c): int => $c->location_id, $drafts))->toBe([$this->place->id, $bar->id])
        ->and(StockCount::query()->count())->toBe(2);
});

it('answers no count for a station with no stock place', function () {
    $shift = $this->f->running($this->f->cakeRoom, $this->f->baker, state: 'counting');

    expect(($this->counts)()->draftsFor($shift, CountType::Closing))->toBe([])
        ->and(StockCount::query()->count())->toBe(0);
});

it('answers the same rows when asked again, with the client uuid derived from the shift, place, type and sequence', function () {
    $this->f->stock($this->f->ingredient(), $this->place, '1');

    $first = ($this->counts)()->draftsFor($this->shift, CountType::Closing);
    $again = ($this->counts)()->draftsFor($this->shift, CountType::Closing);

    expect($again[0]->id)->toBe($first[0]->id)
        ->and(StockCount::query()->count())->toBe(1)
        ->and(StockCountLine::query()->count())->toBe(1)
        ->and($first[0]->client_uuid)->toBe(Uuid::uuid5(Uuid::NAMESPACE_URL, "count:{$this->shift->id}:{$this->place->id}:closing:1")->toString());
});

it('makes the opening and the closing count of a shift two different rows', function () {
    ($this->counts)()->draftsFor($this->shift, CountType::Opening);
    ($this->counts)()->draftsFor($this->shift, CountType::Closing);

    expect(StockCount::query()->orderBy('id')->pluck('type')->map(fn ($t) => $t->value)->all())->toBe(['opening', 'closing']);
});

it('adds the items of the previous count: counted at zero after having stock stays, counted at zero having none drops', function () {
    $flour = $this->f->ingredient();
    $ranOut = $this->f->ingredient();
    $neverHad = $this->f->ingredient();
    $stays = $this->f->ingredient();
    $this->f->stock($flour, $this->place, '100');
    $earlier = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-10-20'], state: 'locked');
    $previous = StockCount::factory()->forShift($earlier, $this->place->id)->closing()->status(CountStatus::Approved)->create(['submitted_at' => now()->subDay()]);
    StockCountLine::factory()->forCount($previous, $flour->id)->counted('100', expected: '100')->create();
    StockCountLine::factory()->forCount($previous, $ranOut->id)->counted('0', expected: '40')->create();
    StockCountLine::factory()->forCount($previous, $neverHad->id)->counted('0', expected: '0')->create();
    StockCountLine::factory()->forCount($previous, $stays->id)->counted('7', expected: '7')->create();

    $drafts = ($this->counts)()->draftsFor($this->shift, CountType::Closing);

    // flour (stock now), ran out (counted at zero, but it had stock), stays (counted above zero); never-had drops out
    expect(array_keys(draftLines($drafts[0])))->toBe([$flour->id, $ranOut->id, $stays->id]);
});

it('uses the newest submitted count of the place as the previous one, not a draft, a rejected or another place\'s', function () {
    $newer = $this->f->ingredient();
    $older = $this->f->ingredient();
    $draftOnly = $this->f->ingredient();
    $rejected = $this->f->ingredient();
    $elsewhere = $this->f->ingredient();
    $bar = $this->f->place($this->f->kitchen, LocationType::Bar);
    $n = 0;
    $make = function (CountStatus $status, string $submitted, int $location, int $item) use (&$n): void {
        $n++;
        $shift = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-09-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)], state: 'locked');
        $count = StockCount::factory()->forShift($shift, $location)->closing()->status($status)->create(['submitted_at' => $submitted]);
        StockCountLine::factory()->forCount($count, $item)->counted('3', expected: '3')->create();
    };
    $make(CountStatus::Approved, '2026-10-10 10:00:00', $this->place->id, $older->id);
    $make(CountStatus::PendingApproval, '2026-10-12 10:00:00', $this->place->id, $newer->id);
    $make(CountStatus::Draft, '2026-10-14 10:00:00', $this->place->id, $draftOnly->id);
    $make(CountStatus::Rejected, '2026-10-13 10:00:00', $this->place->id, $rejected->id);
    $make(CountStatus::Approved, '2026-10-15 10:00:00', $bar->id, $elsewhere->id);

    $drafts = ($this->counts)()->draftsFor($this->shift, CountType::Closing);

    $forPlace = collect($drafts)->firstWhere('location_id', $this->place->id);
    expect(array_keys(draftLines($forPlace)))->toBe([$newer->id]);
});

it('drops an inactive item that was counted at zero, and keeps one that still has stock', function () {
    $gone = $this->f->ingredient('g', ['is_active' => false]);
    $stillHere = $this->f->ingredient('g', ['is_active' => false]);
    $this->f->stock($stillHere, $this->place, '3');
    $earlier = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-10-20'], state: 'locked');
    $previous = StockCount::factory()->forShift($earlier, $this->place->id)->closing()->status(CountStatus::Approved)->create();
    StockCountLine::factory()->forCount($previous, $gone->id)->counted('0', expected: '9')->create();

    $drafts = ($this->counts)()->draftsFor($this->shift, CountType::Closing);

    expect(array_keys(draftLines($drafts[0])))->toBe([$stillHere->id]);
});
