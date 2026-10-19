<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\Reserved;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Contracts\Shortfall;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\Ledger;
use App\Shared\Models\Branch;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CommitsForConcurrency;

/*
 * Phase 0 gate, criterion 3 (group concurrency; ADR 0002, rule 3): the balance row is really locked for the whole
 * reservation transaction. While connection A holds a reservation open, connection B's SELECT ... FOR UPDATE NOWAIT on
 * the same stock_balances row fails at once, and B's own reservation of the last cake cannot get past the lock; once
 * A commits, B sees the cake gone and gets a Shortfall instead of a second sale. Uses CommitsForConcurrency
 * (DatabaseTruncation): RefreshDatabase's wrapping transaction would hide every row from connection B.
 */

uses(CommitsForConcurrency::class);

beforeEach(function () {
    $default = (string) config('database.default');
    config(['database.connections.concurrency_second' => config("database.connections.{$default}")]);
    $this->defaultConnection = $default;

    $this->branch = actInBranch(Branch::factory()->create());
    withActor(User::factory()->create(['branch_id' => $this->branch->id])->id);
    $this->display = stockLocation($this->branch, LocationType::DisplayCase);
    $this->cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    stockIn($this->cake, $this->display, '1'); // the last cake, committed: B can see it

    $this->second = DB::connection('concurrency_second');
    $this->lockProbe = fn (): array => $this->second->transaction(fn (Connection $c): array => $c->select(
        'select id, on_hand, reserved from stock_balances where item_id = ? and location_id = ? for update nowait',
        [$this->cake->id, $this->display->id],
    ));
    $this->line = new ReserveLine($this->cake->id, '1', $this->display->id);
});

afterEach(function () {
    DB::setDefaultConnection($this->defaultConnection);
    while (DB::connection($this->defaultConnection)->transactionLevel() > 0) {
        DB::connection($this->defaultConnection)->rollBack();
    }
    DB::purge('concurrency_second');
});

it('holds the balance row lock while a reservation transaction is open: a second connection with NOWAIT fails', function () {
    expect(($this->lockProbe)())->toHaveCount(1, 'nobody holds the row yet: B locks and releases it at once');

    DB::beginTransaction();
    $result = app(Reservations::class)->reserve([$this->line], (string) Str::uuid());
    expect($result)->toBeInstanceOf(Reserved::class);

    expect(fn () => ($this->lockProbe)())->toThrow(QueryException::class);

    // a plain read is not blocked and still sees the committed state: the reservation is not visible to B yet
    $seen = $this->second->selectOne('select reserved from stock_balances where item_id = ? and location_id = ?', [$this->cake->id, $this->display->id]);
    expect((string) $seen->reserved)->toBe('0.0000');

    DB::commit();

    $after = ($this->lockProbe)();
    expect($after)->toHaveCount(1)
        ->and((string) $after[0]->reserved)->toBe('1.0000');
})->group('gate:p0', 'concurrency', 'module:inventory');

it('makes a second reservation of the last cake wait for the first, then find it gone', function () {
    DB::beginTransaction();
    expect(app(Reservations::class)->reserve([$this->line], (string) Str::uuid()))->toBeInstanceOf(Reserved::class);

    // B, on its own connection, gives up after a second of waiting for A's lock instead of selling the cake twice
    $this->second->statement($this->second->getDriverName() === 'pgsql' ? "set lock_timeout = '1s'" : 'set session innodb_lock_wait_timeout = 1');
    DB::setDefaultConnection('concurrency_second');
    expect(fn () => app(Reservations::class)->reserve([$this->line], (string) Str::uuid()))->toThrow(QueryException::class);
    DB::setDefaultConnection($this->defaultConnection);

    DB::commit();

    // once A has committed, B gets in and is told the cake is gone
    DB::setDefaultConnection('concurrency_second');
    $late = app(Reservations::class)->reserve([$this->line], (string) Str::uuid());
    DB::setDefaultConnection($this->defaultConnection);

    expect($late)->toBeInstanceOf(Shortfall::class)
        ->and($late->ingredientItemId)->toBe($this->cake->id)
        ->and($late->qtyShort->toString())->toBe('1.0000')
        ->and(stockRow($this->cake, $this->display))->on_hand->toBe('1.0000')->reserved->toBe('1.0000');
})->group('gate:p0', 'concurrency', 'module:inventory');

it('refuses to lock outside a transaction: a lock released at once protects nothing', function () {
    expect(DB::transactionLevel())->toBe(0)
        ->and(fn () => app(Ledger::class)->lock([[$this->cake->id, $this->display->id]]))->toThrow(LogicException::class);
})->group('concurrency', 'module:inventory');
