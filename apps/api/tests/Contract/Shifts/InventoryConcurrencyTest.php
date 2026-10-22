<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;

/*
 * Contract: shifts -> inventory, the cases that need a second connection or a second process (plan section 2, cases 3
 * and 8; the other cases are in InventoryContractTest.php). CommitsForConcurrency (DatabaseTruncation): the second
 * connection and the forked children see only committed rows.
 *
 *   - a movement written by a transaction that has not committed is invisible to balanceAt (a count's snapshot must
 *     not see half of someone else's batch);
 *   - Ledger::lock(pairs) inside a transaction returns the on_hand that balanceAt(now) reports after it, and a
 *     concurrent append to a locked pair waits until the transaction ends (what the count's snapshot relies on).
 */

uses(CommitsForConcurrency::class);

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id, 'name_en' => 'Flour']);
});

it('does not see, through balanceAt, a movement written by a transaction that has not committed', function () {
    stockIn($this->flour, $this->kitchen, '4');
    $default = DB::getDefaultConnection();
    config(['database.connections.second' => config("database.connections.{$default}")]);
    $readFromSecond = function () use ($default): string {
        DB::setDefaultConnection('second');
        try {
            return app(Stock::class)->balanceAt($this->flour, $this->kitchen, CarbonImmutable::now()->addHour())->onHand->toString();
        } finally {
            DB::setDefaultConnection($default);
        }
    };

    DB::beginTransaction();
    try {
        stockIn($this->flour, $this->kitchen, '6');
        $inside = app(Stock::class)->balanceAt($this->flour, $this->kitchen, CarbonImmutable::now()->addHour())->onHand->toString();
        $outside = $readFromSecond();
    } finally {
        DB::commit();
    }

    expect($inside)->toBe('10.0000', 'the writer sees its own batch')
        ->and($outside)->toBe('4.0000', 'another connection does not, until the commit')
        ->and($readFromSecond())->toBe('10.0000');
})->group('contract:shifts->inventory', 'contract', 'concurrency');

it('lock(pairs) returns the on_hand balanceAt(now) reports, and a concurrent append to a locked pair waits for the transaction to end', function () {
    stockIn($this->flour, $this->kitchen, '10');
    $pair = [[$this->flour->id, $this->kitchen->id]];

    // in one process: what the lock returns is what balanceAt says
    DB::transaction(function () use ($pair): void {
        $locked = app(Ledger::class)->lock($pair);
        $level = array_values($locked)[0];
        expect($level->onHand->toString())->toBe('10.0000')
            ->and(app(Stock::class)->balanceAt($this->flour, $this->kitchen, CarbonImmutable::now()->addMinute())->onHand->toString())->toBe('10.0000');
    });

    $holder = function () use ($pair): array {
        return DB::transaction(function () use ($pair): array {
            $level = array_values(app(Ledger::class)->lock($pair))[0];
            $seen = app(Stock::class)->balanceAt($this->flour, $this->kitchen, CarbonImmutable::now()->addMinute())->onHand->toString();
            usleep(900_000);

            return ['locked' => $level->onHand->toString(), 'seen' => $seen, 'released_at' => microtime(true)];
        });
    };
    $writer = function (): array {
        usleep(300_000);
        $started = microtime(true);
        DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
        app(Ledger::class)->append(MovementIntent::of($this->flour->id, $this->kitchen->id, '5', MovementReason::PurchaseIn, 'test'));

        return ['started_at' => $started, 'finished_at' => microtime(true)];
    };

    $results = Forks::together([$holder, $writer]);

    expect($results[0]['ok'])->toBeTrue($results[0]['error'] ?? '')
        ->and($results[1]['ok'])->toBeTrue($results[1]['error'] ?? '')
        ->and($results[0]['value']['locked'])->toBe('10.0000')
        ->and($results[0]['value']['seen'])->toBe('10.0000')
        ->and($results[1]['value']['finished_at'])->toBeGreaterThanOrEqual($results[0]['value']['released_at'] - 0.05, 'the append waited for the holder\'s transaction')
        ->and(app(Stock::class)->of($this->flour, $this->kitchen)->onHand->toString())->toBe('15.0000');
})->group('contract:shifts->inventory', 'contract', 'concurrency');
