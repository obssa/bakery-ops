<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;

/*
 * Step 2.2b hardening (found in 2.2a), group concurrency: the first movement of an item at a place creates its
 * stock_balances row. Two processes doing that for the same new (item, location) at the same instant used to deadlock:
 * both ran SELECT ... FOR UPDATE on the missing row (InnoDB takes a gap lock, and two gap locks do not conflict), then
 * both INSERTed into the gap the other one held. The engine killed one of them (1213 / 40001). Laravel retries an
 * outermost transaction, but inside an AuditedAction the batch is a savepoint and cannot be retried, so the request
 * failed. Each fork below runs its batch nested in one outer attempt (as ConfirmPurchase will), so a deadlock surfaces.
 * The rows are created one pair at a time in the lock order, inserting before locking, so nobody ever locks a gap.
 * CommitsForConcurrency (DatabaseTruncation): the children connect on their own and must see the committed rows.
 */

uses(CommitsForConcurrency::class);

function firstMovementDeadlocks(): int
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

    // one batch in one outer transaction with one attempt: the ledger's own retry cannot hide a deadlock
    $this->receive = fn (array $pairs, string $qty): Closure => function () use ($pairs, $qty): string {
        DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
        DB::transaction(function () use ($pairs, $qty): void {
            app(Ledger::class)->appendMany(array_map(
                static fn (array $p): MovementIntent => MovementIntent::of($p[0], $p[1], $qty, MovementReason::PurchaseIn, 'test'),
                $pairs,
            ));
        }, 1);

        return 'ok';
    };
});

it('lets two processes make the first movement of one new item at one place without a deadlock (30 rounds)', function () {
    $deadlocksBefore = firstMovementDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        $item = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
        $results = Forks::together([
            ($this->receive)([[$item->id, $this->store->id]], '10'),
            ($this->receive)([[$item->id, $this->store->id]], '5'),
        ]);
        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true) {
                $failures[] = "round {$round}, process {$i}: ".($r['error'] ?? 'no result');
            }
        }
        $rows = DB::table('stock_balances')->where('item_id', $item->id)->get();
        if ($rows->count() !== 1 || $rows[0]->on_hand !== '15.0000' || DB::table('stock_movements')->where('item_id', $item->id)->count() !== 2) {
            $failures[] = "round {$round}: expected one balance row of 15.0000 and two movements, got ".json_encode($rows);
        }
    }

    expect($failures)->toBe([])->and(firstMovementDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory');

it('lets two processes create the same two new balance rows, named in opposite order, without a deadlock (30 rounds)', function () {
    $deadlocksBefore = firstMovementDeadlocks();
    $failures = [];

    foreach (range(1, 30) as $round) {
        $a = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
        $b = Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id]);
        $results = Forks::together([
            ($this->receive)([[$a->id, $this->store->id], [$b->id, $this->kitchen->id]], '4'),
            ($this->receive)([[$b->id, $this->kitchen->id], [$a->id, $this->store->id]], '6'),
        ]);
        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true) {
                $failures[] = "round {$round}, process {$i}: ".($r['error'] ?? 'no result');
            }
        }
        foreach ([[$a, $this->store], [$b, $this->kitchen]] as [$item, $location]) {
            $on = DB::table('stock_balances')->where('item_id', $item->id)->where('location_id', $location->id)->value('on_hand');
            if ($on !== '10.0000') {
                $failures[] = "round {$round}: item {$item->id} at {$location->id} holds ".json_encode($on).', expected 10.0000';
            }
        }
    }

    expect($failures)->toBe([])->and(firstMovementDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory');
