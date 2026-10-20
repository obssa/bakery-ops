<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Contracts\Shortfall;
use App\Modules\Inventory\Enums\LocationType;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CommitsForConcurrency;

/*
 * Contract: orders -> inventory, "lock order is deterministic: 50 interleavings never deadlock" (group concurrency).
 *
 * The way Orders sends (ADR 0008): ONE outer transaction per order, one reservation ref per line, all lines in one
 * reserveMany call. Here two orders whose lines share four ingredients, sent in opposite line orders, run in two real
 * processes (pcntl_fork) that start together, 50 rounds. Each process wraps the call in its own outer transaction
 * with a single attempt, exactly like SendOrder, so a deadlock would surface as an error instead of being retried
 * away; the engine's own deadlock counter must not move either. CommitsForConcurrency (DatabaseTruncation): the
 * children connect on their own and must see the committed stock.
 */

uses(CommitsForConcurrency::class);

/**
 * Run each job in its own forked process at the same moment; returns per job ['ok' => bool, 'value' | 'error'].
 * The parent drops its database connections before forking (a connection shared with a child would be closed under
 * it) and reconnects lazily afterwards. A child never returns into PHPUnit: it writes its result and kills itself.
 *
 * @param  list<Closure(): mixed>  $jobs
 * @return list<array{ok: bool, value?: mixed, error?: string}>
 */
function forkTogether(array $jobs, int $timeoutSeconds = 60): array
{
    foreach (array_keys(DB::getConnections()) as $name) {
        DB::disconnect($name);
    }
    $dir = sys_get_temp_dir().'/bakery-fork-'.Str::random(12);
    mkdir($dir);
    $startAt = microtime(true) + 0.05;
    $pids = [];
    foreach ($jobs as $i => $job) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }
        if ($pid === 0) {
            $result = ['ok' => false, 'error' => 'did not run'];
            try {
                DB::purge();
                while (microtime(true) < $startAt) {
                    usleep(50);
                }
                $result = ['ok' => true, 'value' => $job()];
            } catch (Throwable $e) {
                $result = ['ok' => false, 'error' => $e::class.': '.$e->getMessage()];
            }
            file_put_contents("{$dir}/{$i}.json", json_encode($result));
            try {
                DB::disconnect();
            } catch (Throwable) {
            }
            posix_kill(posix_getpid(), SIGKILL);
        }
        $pids[$i] = $pid;
    }

    $deadline = microtime(true) + $timeoutSeconds;
    $pending = $pids;
    while ($pending !== [] && microtime(true) < $deadline) {
        foreach ($pending as $i => $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                unset($pending[$i]);
            }
        }
        usleep(1000);
    }
    foreach ($pending as $pid) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }

    $results = [];
    foreach (array_keys($jobs) as $i) {
        $file = "{$dir}/{$i}.json";
        $results[$i] = is_file($file)
            ? (array) json_decode((string) file_get_contents($file), true)
            : ['ok' => false, 'error' => isset($pending[$i]) ? "timed out after {$timeoutSeconds} s" : 'no result'];
        @unlink($file);
    }
    @rmdir($dir);

    return $results;
}

/** The engine's count of deadlocks since it started (MariaDB / MySQL status, PostgreSQL statistics). */
function engineDeadlocks(): int
{
    if (DB::getDriverName() === 'pgsql') {
        return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
    }
    $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

    return $row === null ? 0 : (int) $row->Value;
}

beforeEach(function () {
    expect(function_exists('pcntl_fork') && function_exists('posix_kill'))->toBeTrue('pcntl and posix are in the API image and in CI (setup-api)');

    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    withActor(User::factory()->create(['branch_id' => $b])->id);
    $kitchenStation = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen, $kitchenStation);

    // four ingredients that both products use, in different amounts
    $this->ingredients = [];
    foreach (['Bread', 'Chicken', 'Lettuce', 'Butter'] as $name) {
        $this->ingredients[$name] = Item::factory()->ingredient('g')->create(['branch_id' => $b, 'name_en' => $name]);
        stockIn($this->ingredients[$name], $this->kitchen, '100000');
    }
    $recipe = function (string $name, array $qty) use ($b): Item {
        $product = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b, 'name_en' => $name]);
        $recipe = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $product->id, 'version' => 1, 'is_active' => true, 'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id]);
        foreach ($qty as $ingredient => $grams) {
            RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $this->ingredients[$ingredient]->id, 'qty' => $grams, 'unit_id' => catalogUnit('g')->id]);
        }

        return $product;
    };
    $this->sandwich = $recipe('Sandwich', ['Bread' => '60', 'Chicken' => '120', 'Lettuce' => '20', 'Butter' => '10']);
    $this->club = $recipe('Club', ['Butter' => '15', 'Lettuce' => '25', 'Chicken' => '90', 'Bread' => '90']);
});

it('never deadlocks when two orders whose lines share ingredients are sent at once in opposite line orders (50 rounds)', function () {
    $deadlocksBefore = engineDeadlocks();
    $line = fn (Item $item): ReserveLine => new ReserveLine($item->id, '1', $this->kitchen->id);
    // what SendOrder does: its own transaction, one attempt (a deadlock must surface, not be retried away)
    $send = fn (array $lines): Closure => function () use ($lines): string {
        DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
        $byRef = [];
        foreach ($lines as $l) {
            $byRef[(string) Str::uuid()] = [$l];
        }
        $result = DB::transaction(fn () => app(Reservations::class)->reserveMany($byRef), 1);

        return $result instanceof Shortfall ? 'shortfall' : 'reserved '.count($result);
    };

    $failures = [];
    foreach (range(1, 50) as $round) {
        $results = forkTogether([
            $send([$line($this->sandwich), $line($this->club)]),
            $send([$line($this->club), $line($this->sandwich)]),
        ]);
        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true || ($r['value'] ?? null) !== 'reserved 2') {
                $failures[] = "round {$round}, order {$i}: ".($r['error'] ?? json_encode($r['value'] ?? null));
            }
        }
    }

    expect($failures)->toBe([], 'every order is reserved, none deadlocks or waits out its lock')
        ->and(engineDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');

    // 100 orders, each one sandwich and one club
    foreach (['Bread' => '15000.0000', 'Chicken' => '21000.0000', 'Lettuce' => '4500.0000', 'Butter' => '2500.0000'] as $name => $reserved) {
        expect(stockRow($this->ingredients[$name], $this->kitchen))->on_hand->toBe('100000.0000')->reserved->toBe($reserved);
    }
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('contract:orders->inventory', 'contract', 'concurrency');
