<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\InvalidMovement;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Models\Branch;
use App\Shared\Values\Quantity;
use Illuminate\Support\Facades\DB;

/*
 * Ledger::lockItems (step 2.2a): the cost core needs an item's total on_hand across the branch's locations, read under
 * the same row locks the ledger takes (stock_balances ascending (item_id, location_id), the first half of the fixed lock
 * order; item_costs ascending item_id comes second, in CostLedger). Only the Ledger namespace may name the stock tables
 * (tests/Arch/LedgerIsSoleWriterTest.php), so the read lives here.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);
    $this->a = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
    $this->b = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
});

it('sums on_hand across locations per item, keyed by item id ascending, zero for an item that never moved', function () {
    stockIn($this->a, $this->store, '10');
    stockIn($this->a, $this->kitchen, '2.5');
    stockIn($this->b, $this->kitchen, '7');
    $never = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);

    $totals = DB::transaction(fn () => app(Ledger::class)->lockItems([$this->b->id, $never->id, $this->a->id]));

    expect(array_keys($totals))->toBe([$this->a->id, $this->b->id, $never->id])
        ->and($totals[$this->a->id])->toBeInstanceOf(Quantity::class)
        ->and($totals[$this->a->id]->toString())->toBe('12.5000')
        ->and($totals[$this->b->id]->toString())->toBe('7.0000')
        ->and($totals[$never->id]->toString())->toBe('0.0000');
})->group('module:inventory', 'feature');

it('counts on_hand, not available: a reservation does not take stock out of the average', function () {
    stockIn($this->a, $this->store, '10');
    app(Ledger::class)->append(App\Modules\Inventory\Ledger\MovementIntent::of($this->a->id, $this->store->id, '4', MovementReason::Reservation, 'test', null, 'ref-1'));

    $totals = DB::transaction(fn () => app(Ledger::class)->lockItems([$this->a->id]));

    expect($totals[$this->a->id]->toString())->toBe('10.0000');
})->group('module:inventory', 'feature');

it('locks the rows ascending by item and location with FOR UPDATE', function () {
    stockIn($this->b, $this->kitchen, '1');
    stockIn($this->a, $this->store, '1');
    $queries = [];
    DB::listen(function ($q) use (&$queries): void {
        $queries[] = str_replace(['`', '"'], '', strtolower($q->sql));
    });

    DB::transaction(fn () => app(Ledger::class)->lockItems([$this->b->id, $this->a->id]));

    $lock = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from stock_balances') && str_contains($sql, 'for update')));
    expect($lock)->toHaveCount(1)
        ->and($lock[0])->toContain('order by item_id asc, location_id asc');
})->group('module:inventory', 'feature');

it('refuses an unknown item and an item of another branch, and answers an empty list with nothing', function () {
    $other = Item::factory()->ingredient('g')->create(['branch_id' => Branch::factory()->create()->id]);

    expect(fn () => DB::transaction(fn () => app(Ledger::class)->lockItems([$other->id])))->toThrow(InvalidMovement::class)
        ->and(fn () => DB::transaction(fn () => app(Ledger::class)->lockItems([999999])))->toThrow(InvalidMovement::class)
        ->and(DB::transaction(fn () => app(Ledger::class)->lockItems([])))->toBe([]);
})->group('module:inventory', 'feature');
