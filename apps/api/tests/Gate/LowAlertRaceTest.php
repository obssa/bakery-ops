<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Jobs\EvaluateBalance;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ReorderRule;
use App\Shared\Events\StockLow;
use App\Shared\Events\StockOut;
use App\Shared\Events\StockRecovered;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;

/*
 * Step 2.6a, group concurrency (plan sections 6 item 6, 7 and 10; ADR 0011): the low episode is claimed by an INSERT that the
 * unique key arbitrates, so two evaluations of one balance at the same moment announce StockLow once (and StockRecovered
 * once on the way back), and the evaluation never takes FOR UPDATE on an episode or rule row that does not exist yet (two
 * processes would gap-lock it and deadlock, traps.md "First-row race"). Two processes on two connections; every round uses a
 * new item so the first episode of a balance is the thing that races. The engine's deadlock counter must not move.
 * CommitsForConcurrency (DatabaseTruncation): the children must see committed rows.
 */

uses(CommitsForConcurrency::class);

const LOW_RACE_ROUNDS = 30;

function lowRaceDeadlocks(): int
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
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    config(['cache.default' => 'database']); // the unique-job lock must be shared between the processes, as on the live host
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);
    $this->item = fn (): Item => Item::factory()->ingredient('g')->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->admin->id]);
    // a rule made AFTER the stock, so nothing evaluated it yet and the first episode is what the children race to claim
    $this->rule = fn (Item $item, ?int $place, string $min): ReorderRule => ReorderRule::factory()->create([
        'branch_id' => $this->branch->id, 'item_id' => $item->id, 'location_id' => $place, 'location_key' => $place ?? 0, 'min_qty' => $min, 'reorder_qty' => '1000',
    ]);
    // a job for Forks::together: run $work in the child with the alert events recorded, report how many of each it announced
    $this->announcing = fn (Closure $work): Closure => function () use ($work): array {
        DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
        Event::fake([StockLow::class, StockOut::class, StockRecovered::class]);
        $work();

        return [
            'low' => Event::dispatched(StockLow::class)->count(),
            'out' => Event::dispatched(StockOut::class)->count(),
            'recovered' => Event::dispatched(StockRecovered::class)->count(),
        ];
    };
    $this->evaluate = fn (Item $item, $at): Closure => fn () => app()->call([new EvaluateBalance($this->branch->id, $item->id, $at->id), 'handle']);
    $this->open = fn (Item $item, string $type = 'low') => DB::table('stock_alert_states')->where('item_id', $item->id)->where('type', $type)->where('is_active', 1)->count();
    $this->total = fn (array $results, string $field): int => array_sum(array_map(fn (array $r): int => (int) ($r['value'][$field] ?? 0), $results));
    $this->allOk = fn (array $results): bool => array_reduce($results, fn (bool $ok, array $r): bool => $ok && ($r['ok'] ?? false), true);
});

it('announces a low episode once when two evaluations of the same balance run at the same moment (30 rounds)', function () {
    $deadlocksBefore = lowRaceDeadlocks();
    $failures = [];
    foreach (range(1, LOW_RACE_ROUNDS) as $round) {
        $item = ($this->item)();
        stockIn($item, $this->store, '400');
        ($this->rule)($item, $this->store->id, '500');

        $results = Forks::together([
            ($this->announcing)(($this->evaluate)($item, $this->store)),
            ($this->announcing)(($this->evaluate)($item, $this->store)),
        ]);

        if (! ($this->allOk)($results) || ($this->total)($results, 'low') !== 1 || ($this->open)($item) !== 1 || DB::table('stock_alert_states')->where('item_id', $item->id)->where('type', 'low')->count() !== 1) {
            $failures[] = "round {$round}: ".json_encode($results).' open '.($this->open)($item);
        }
    }

    expect($failures)->toBe([])->and(lowRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory', 'feature');

it('announces a recovery once when two evaluations race to close the same low episode (30 rounds)', function () {
    $deadlocksBefore = lowRaceDeadlocks();
    $failures = [];
    foreach (range(1, LOW_RACE_ROUNDS) as $round) {
        $item = ($this->item)();
        stockIn($item, $this->store, '400');
        $rule = ($this->rule)($item, $this->store->id, '500');
        app()->call([new EvaluateBalance($this->branch->id, $item->id, $this->store->id), 'handle']); // opens it, in the parent
        $rule->forceFill(['min_qty' => '100'])->save();                                              // now 400 is above the minimum

        $results = Forks::together([
            ($this->announcing)(($this->evaluate)($item, $this->store)),
            ($this->announcing)(($this->evaluate)($item, $this->store)),
        ]);

        if (! ($this->allOk)($results) || ($this->total)($results, 'recovered') !== 1 || ($this->open)($item) !== 0 || DB::table('stock_alert_states')->where('item_id', $item->id)->where('type', 'low')->count() !== 1) {
            $failures[] = "round {$round}: ".json_encode($results);
        }
    }

    expect($failures)->toBe([])->and(lowRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory', 'feature');

it('announces once when a movement and an evaluation of its balance race, whichever commits first (30 rounds)', function () {
    $deadlocksBefore = lowRaceDeadlocks();
    $failures = [];
    foreach (range(1, LOW_RACE_ROUNDS) as $round) {
        $item = ($this->item)();
        stockIn($item, $this->store, '600');
        ($this->rule)($item, $this->store->id, '500');

        $results = Forks::together([
            ($this->announcing)(function () use ($item): void {
                stockIn($item, $this->store, '-200', MovementReason::Waste);
            }),
            ($this->announcing)(($this->evaluate)($item, $this->store)),
        ]);

        if (! ($this->allOk)($results) || ($this->total)($results, 'low') !== 1 || ($this->open)($item) !== 1) {
            $failures[] = "round {$round}: ".json_encode($results).' open '.($this->open)($item);
        }
    }

    expect($failures)->toBe([])->and(lowRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory', 'feature');

it('opens one branch-wide episode when two places fall at once and the sum crosses the minimum, deadlock-free (30 rounds)', function () {
    $deadlocksBefore = lowRaceDeadlocks();
    $failures = [];
    foreach (range(1, LOW_RACE_ROUNDS) as $round) {
        $item = ($this->item)();
        stockIn($item, $this->store, '400');
        stockIn($item, $this->kitchen, '400');
        ($this->rule)($item, null, '500'); // 800 across the branch; each place alone falling by 200 leaves 600, both leave 400

        $results = Forks::together([
            ($this->announcing)(function () use ($item): void {
                stockIn($item, $this->store, '-200', MovementReason::Waste);
            }),
            ($this->announcing)(function () use ($item): void {
                stockIn($item, $this->kitchen, '-200', MovementReason::Waste);
            }),
        ]);

        if (! ($this->allOk)($results) || ($this->total)($results, 'low') !== 1 || ($this->open)($item) !== 1
            || DB::table('stock_alert_states')->where('item_id', $item->id)->where('type', 'low')->where('location_key', 0)->count() !== 1) {
            $failures[] = "round {$round}: ".json_encode($results).' open '.($this->open)($item);
        }
    }

    expect($failures)->toBe([])->and(lowRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:inventory', 'feature');
