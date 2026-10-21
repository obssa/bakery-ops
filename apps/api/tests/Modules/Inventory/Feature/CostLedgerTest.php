<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Costs\CostLedger;
use App\Modules\Inventory\Costs\CostReceipt;
use App\Modules\Inventory\Costs\CostStep;
use App\Modules\Inventory\Costs\MovingAverage;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\InvalidMovement;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ItemCost;
use App\Modules\Inventory\Models\ItemCostHistory;
use App\Shared\Models\Branch;
use App\Shared\Values\CurrencyMismatch;
use App\Shared\Values\UnitCost;
use Illuminate\Support\Facades\DB;

/*
 * Costs\CostLedger (step 2.2a; phase-2 plan section 6 item 2 and section 10.3). It runs inside the caller's transaction
 * AFTER the purchase_in movements are written (ConfirmPurchase, step 2.2b): it locks the item's stock_balances rows
 * ascending (item_id, location_id) through the Ledger, then the item_costs rows ascending item_id, reads the item's total
 * on_hand across the branch's locations, takes away what the receipts just added to get the quantity before the purchase,
 * and for each receipt in order writes the new average (MovingAverage::next) and one item_cost_history row.
 * Unvalued stock does not dilute: with no item_costs row the first receipt sets the average to its own unit cost.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->user = makeUser($this->branch, 'admin');
    withActor($this->user->id);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);
    $this->flour = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id, 'name_en' => 'Flour']);
    $this->costs = app(CostLedger::class);

    // what ConfirmPurchase does for one item: the movement first, then the cost under the same transaction
    $this->buy = function (Item $item, string $qty, string $cost, $location = null, ?int $lineId = null): array {
        return DB::transaction(function () use ($item, $qty, $cost, $location, $lineId): array {
            stockIn($item, $location ?? $this->store, $qty);

            return $this->costs->receive($item->id, [new CostReceipt($qty, $cost, $lineId)]);
        });
    };
    $this->cost = fn (Item $item): ?object => DB::table('item_costs')->where('item_id', $item->id)->first();
    $this->history = fn (Item $item): array => DB::table('item_cost_history')->where('item_id', $item->id)->orderBy('id')->get()->all();
});

it('sets the average to its own cost on the first purchase and writes one history row', function () {
    $steps = ($this->buy)($this->flour, '10', '5');

    $cost = ($this->cost)($this->flour);
    expect($cost)->moving_avg_cost->toBe('5.0000')->qty_basis->toBe('10.0000')->currency->toBe('ETB')->version->toBe(1)
        ->and($cost->branch_id)->toBe($this->branch->id)
        ->and($steps)->toHaveCount(1)
        ->and($steps[0])->toBeInstanceOf(CostStep::class)
        ->and($steps[0]->qtyBefore->toString())->toBe('0.0000')
        ->and($steps[0]->costBefore)->toBeNull()
        ->and($steps[0]->costAfter->toString())->toBe('5.0000');

    $history = ($this->history)($this->flour);
    expect($history)->toHaveCount(1)
        ->and($history[0])->source->toBe('purchase')->qty_before->toBe('0.0000')->cost_before->toBeNull()->qty_in->toBe('10.0000')
        ->unit_cost_in->toBe('5.0000')->cost_after->toBe('5.0000')->user_id->toBe($this->user->id)->acting_user_id->toBeNull();
})->group('module:inventory', 'feature');

it('weights the second purchase by the stock on hand and bumps the version', function () {
    ($this->buy)($this->flour, '10', '5');
    ($this->buy)($this->flour, '10', '6');

    expect(($this->cost)($this->flour))->moving_avg_cost->toBe('5.5000')->qty_basis->toBe('20.0000')->version->toBe(2);
    $history = ($this->history)($this->flour);
    expect($history)->toHaveCount(2)
        ->and($history[1])->qty_before->toBe('10.0000')->cost_before->toBe('5.0000')->qty_in->toBe('10.0000')->unit_cost_in->toBe('6.0000')->cost_after->toBe('5.5000');
})->group('module:inventory', 'feature');

it('does not let stock with no recorded cost dilute the average', function () {
    // 100 g arrived before any cost was known (an opening count, say): they are unvalued
    stockIn($this->flour, $this->store, '100', MovementReason::PurchaseIn);
    ($this->buy)($this->flour, '10', '5');

    expect(($this->cost)($this->flour))->moving_avg_cost->toBe('5.0000')->qty_basis->toBe('110.0000');
    $history = ($this->history)($this->flour);
    expect($history[0])->qty_before->toBe('100.0000')->cost_before->toBeNull()->cost_after->toBe('5.0000');
})->group('module:inventory', 'feature');

it('counts the item on hand across every location of the branch, not only where it was received', function () {
    ($this->buy)($this->flour, '20', '4');
    stockIn($this->flour, $this->kitchen, '10');   // ten more in the kitchen, already valued at the average
    ($this->buy)($this->flour, '10', '6');

    // (30 x 4 + 10 x 6) / 40
    expect(($this->cost)($this->flour))->moving_avg_cost->toBe('4.5000')->qty_basis->toBe('40.0000');
    expect(($this->history)($this->flour)[1])->qty_before->toBe('30.0000');
})->group('module:inventory', 'feature');

it('applies several receipts for one item in order, each seeing what the earlier ones added', function () {
    $steps = DB::transaction(function () {
        stockIn($this->flour, $this->store, '10');
        stockIn($this->flour, $this->store, '10');
        stockIn($this->flour, $this->store, '10');

        return $this->costs->receive($this->flour->id, [
            new CostReceipt('10', '2', 11), new CostReceipt('10', '4', 12), new CostReceipt('10', '6', 13),
        ]);
    });

    expect(array_map(fn (CostStep $s): string => $s->qtyBefore->toString(), $steps))->toBe(['0.0000', '10.0000', '20.0000'])
        ->and(array_map(fn (CostStep $s): string => $s->costAfter->toString(), $steps))->toBe(['2.0000', '3.0000', '4.0000'])
        ->and(array_map(fn (CostStep $s): ?int => $s->purchaseLineId, $steps))->toBe([11, 12, 13]);
    expect(($this->cost)($this->flour))->moving_avg_cost->toBe('4.0000')->qty_basis->toBe('30.0000');
    $history = ($this->history)($this->flour);
    expect(array_map(fn ($h) => $h->cost_after, $history))->toBe(['2.0000', '3.0000', '4.0000'])
        ->and(array_map(fn ($h) => $h->cost_before, $history))->toBe([null, '2.0000', '3.0000']);
})->group('module:inventory', 'feature');

it('rounds once at each receipt and carries the stored value into the next', function () {
    $steps = DB::transaction(function () {
        stockIn($this->flour, $this->store, '3');
        stockIn($this->flour, $this->store, '4');

        return $this->costs->receive($this->flour->id, [new CostReceipt('3', '0.3333'), new CostReceipt('4', '0.3334')]);
    });

    $expected = MovingAverage::next('3', UnitCost::of('0.3333'), '4', '0.3334');
    expect($steps[1]->costAfter->toString())->toBe($expected->toString())
        ->and(($this->cost)($this->flour)->moving_avg_cost)->toBe($expected->toString());
})->group('module:inventory', 'feature');

it('lets a free item (cost 0) pull the average down', function () {
    ($this->buy)($this->flour, '10', '5');
    ($this->buy)($this->flour, '10', '0');

    expect(($this->cost)($this->flour)->moving_avg_cost)->toBe('2.5000');
})->group('module:inventory', 'feature');

it('keeps a separate average for each item', function () {
    $sugar = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id, 'name_en' => 'Sugar']);
    ($this->buy)($this->flour, '10', '5');
    ($this->buy)($sugar, '10', '9');

    expect(($this->cost)($this->flour)->moving_avg_cost)->toBe('5.0000')->and(($this->cost)($sugar)->moving_avg_cost)->toBe('9.0000');
})->group('module:inventory', 'feature');

it('writes the history of several items in ascending item id whatever order they are given', function () {
    $sugar = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id, 'name_en' => 'Sugar']);
    $salt = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id, 'name_en' => 'Salt']);
    $byItem = DB::transaction(function () use ($sugar, $salt) {
        foreach ([$salt, $this->flour, $sugar] as $item) {
            stockIn($item, $this->store, '5');
        }

        return $this->costs->receiveMany([
            $salt->id => [new CostReceipt('5', '1')],
            $sugar->id => [new CostReceipt('5', '2')],
            $this->flour->id => [new CostReceipt('5', '3')],
        ]);
    });

    $ids = [$this->flour->id, $sugar->id, $salt->id];
    sort($ids);
    expect(array_keys($byItem))->toBe($ids);
    $rows = DB::table('item_cost_history')->orderBy('id')->pluck('item_id')->all();
    expect(array_map('intval', $rows))->toBe($ids);
})->group('module:inventory', 'feature');

it('records the acting user beside the user', function () {
    $waiter = makeUser($this->branch, 'waiter');
    withActor($this->user->id, $waiter->id);
    ($this->buy)($this->flour, '10', '5');

    expect(($this->history)($this->flour)[0])->user_id->toBe($this->user->id)->acting_user_id->toBe($waiter->id);
})->group('module:inventory', 'feature');

it('refuses receipts the ledger does not hold yet: the movements are written first', function () {
    expect(fn () => DB::transaction(fn () => $this->costs->receive($this->flour->id, [new CostReceipt('10', '5')])))
        ->toThrow(LogicException::class);
    stockIn($this->flour, $this->store, '4');
    expect(fn () => DB::transaction(fn () => $this->costs->receive($this->flour->id, [new CostReceipt('10', '5')])))
        ->toThrow(LogicException::class);

    expect(DB::table('item_costs')->count())->toBe(0)->and(DB::table('item_cost_history')->count())->toBe(0);
})->group('module:inventory', 'feature');

it('refuses a receipt in another currency than the one the average is kept in', function () {
    ($this->buy)($this->flour, '10', '5');

    expect(fn () => DB::transaction(function () {
        stockIn($this->flour, $this->store, '10');

        return $this->costs->receive($this->flour->id, [new CostReceipt('10', '5', null, 'USD')]);
    }))->toThrow(CurrencyMismatch::class);
    expect(($this->cost)($this->flour))->moving_avg_cost->toBe('5.0000')->version->toBe(1);
})->group('module:inventory', 'feature');

it('refuses an item of another branch and an unknown item', function () {
    $other = Item::factory()->ingredient('g')->create(['branch_id' => Branch::factory()->create()->id]);

    expect(fn () => DB::transaction(fn () => $this->costs->receive($other->id, [new CostReceipt('1', '1')])))->toThrow(InvalidMovement::class)
        ->and(fn () => DB::transaction(fn () => $this->costs->receive(999999, [new CostReceipt('1', '1')])))->toThrow(InvalidMovement::class);
})->group('module:inventory', 'feature');

it('refuses a receipt of nothing or with a negative cost before touching anything', function () {
    expect(fn () => new CostReceipt('0', '5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CostReceipt('-1', '5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CostReceipt('1', '-5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->costs->receive($this->flour->id, []))->toThrow(InvalidArgumentException::class);
})->group('module:inventory', 'feature');

it('leaves stock untouched: it reads the ledger, it never writes a movement', function () {
    ($this->buy)($this->flour, '10', '5');
    $movements = DB::table('stock_movements')->count();
    $before = stockRow($this->flour, $this->store);

    DB::transaction(function () {
        stockIn($this->flour, $this->store, '5');
        $this->costs->receive($this->flour->id, [new CostReceipt('5', '7')]);
    });

    expect(DB::table('stock_movements')->count())->toBe($movements + 1)
        ->and(stockRow($this->flour, $this->store)->on_hand)->toBe('15.0000')
        ->and($before->on_hand)->toBe('10.0000');
})->group('module:inventory', 'feature');

it('refuses to change or delete a history row, in the model as in the database', function () {
    ($this->buy)($this->flour, '10', '5');
    $row = ItemCostHistory::query()->firstOrFail();

    expect(fn () => $row->forceFill(['cost_after' => '9'])->save())->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);
    expect(ItemCost::query()->where('item_id', $this->flour->id)->firstOrFail()->moving_avg_cost)->toBeInstanceOf(UnitCost::class);
})->group('module:inventory', 'feature');
