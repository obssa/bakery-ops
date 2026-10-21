<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Costs\CostLedger;
use App\Modules\Inventory\Costs\CostReceipt;
use App\Modules\Inventory\Costs\MovingAverage;
use App\Modules\Inventory\Enums\LocationType;
use App\Shared\Models\Branch;
use App\Shared\Values\Quantity;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;

/*
 * Step 2.2a, group concurrency (phase-2 plan section 6 item 2): two cost updates for one item serialize. Two purchases of
 * the same item are confirmed at the same moment in two processes, each in its own transaction (as ConfirmPurchase will):
 * write the purchase_in movement, then CostLedger. The balance rows are locked first (ascending), then item_costs, so the
 * second one waits for the first and reads the quantity and the average the first left. The history shows a chain
 * (the second row starts where the first ended), the stored average is the one a sequential run gives for the order the
 * database chose, and nothing deadlocks, also when two purchases of the same two items name them in opposite order.
 * CommitsForConcurrency (DatabaseTruncation): the children connect on their own and must see the committed rows.
 */

uses(CommitsForConcurrency::class);

function costRaceDeadlocks(): int
{
    if (DB::getDriverName() === 'pgsql') {
        return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
    }
    $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

    return $row === null ? 0 : (int) $row->Value;
}

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->user = makeUser($this->branch, 'admin');
    withActor($this->user->id);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);

    // what ConfirmPurchase does for the items of one purchase, in one transaction with one attempt
    $this->confirm = fn (array $byItem): Closure => function () use ($byItem): string {
        DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
        DB::transaction(function () use ($byItem): void {
            $receipts = [];
            foreach ($byItem as $itemId => [$qty, $cost]) {
                stockIn($itemId, $this->store, $qty);
                $receipts[$itemId] = [new CostReceipt($qty, $cost)];
            }
            app(CostLedger::class)->receiveMany($receipts);
        }, 1);

        return 'ok';
    };
});

it('serializes two cost updates for one item: the history is a chain and the average is the sequential one (20 rounds)', function () {
    $deadlocksBefore = costRaceDeadlocks();
    $failures = [];

    foreach (range(1, 20) as $round) {
        $item = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
        // some unvalued stock on hand first, so the first purchase of the round has a quantity before it too
        stockIn($item, $this->kitchen, '7');

        $results = Forks::together([
            ($this->confirm)([$item->id => ['10', '5']]),
            ($this->confirm)([$item->id => ['30', '9.1234']]),
        ]);
        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true) {
                $failures[] = "round {$round}, purchase {$i}: ".($r['error'] ?? 'no result');
            }
        }

        $history = DB::table('item_cost_history')->where('item_id', $item->id)->orderBy('id')->get()->all();
        if (count($history) !== 2) {
            $failures[] = "round {$round}: ".count($history).' history rows, expected 2';

            continue;
        }
        [$first, $second] = $history;
        // the first one to run saw the 7 unvalued units and no cost; the second saw the first's quantity and average
        $chained = $first->qty_before === '7.0000' && $first->cost_before === null
            && $second->qty_before === Quantity::of($first->qty_before)->plus($first->qty_in)->toString() && $second->cost_before === $first->cost_after;
        if (! $chained) {
            $failures[] = "round {$round}: history is not a chain: ".json_encode($history);
        }
        $expected = MovingAverage::next($second->qty_before, $second->cost_before, $second->qty_in, $second->unit_cost_in)->toString();
        $row = DB::table('item_costs')->where('item_id', $item->id)->first();
        if ($row === null || $row->moving_avg_cost !== $expected || $row->moving_avg_cost !== $second->cost_after || $row->qty_basis !== '47.0000' || (int) $row->version !== 2) {
            $failures[] = "round {$round}: item_costs ".json_encode($row)." expected {$expected} over 47.0000, version 2";
        }
    }

    expect($failures)->toBe([])->and(costRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory');

it('never deadlocks when two purchases of the same two items name them in opposite order (20 rounds)', function () {
    $deadlocksBefore = costRaceDeadlocks();
    $failures = [];

    foreach (range(1, 20) as $round) {
        $a = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
        $b = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
        $results = Forks::together([
            ($this->confirm)([$a->id => ['10', '2'], $b->id => ['10', '3']]),
            ($this->confirm)([$b->id => ['5', '4'], $a->id => ['5', '6']]),
        ]);
        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true) {
                $failures[] = "round {$round}, purchase {$i}: ".($r['error'] ?? 'no result');
            }
        }
        foreach ([$a, $b] as $item) {
            if (DB::table('item_cost_history')->where('item_id', $item->id)->count() !== 2 || (int) DB::table('item_costs')->where('item_id', $item->id)->value('version') !== 2) {
                $failures[] = "round {$round}: item {$item->id} does not have two chained updates";
            }
        }
    }

    expect($failures)->toBe([])->and(costRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory');
