<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\NotificationDedupe;
use App\Modules\Notifications\Support\Dedupe;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchNotResolved;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * Step 2.5a (phase-2 plan section 3 migration 13, ADR 0011; traps.md "First-row race"): the delivery gate for episodic alerts.
 * claim() inserts the active row or finds it, using the unique key and nothing else: a SELECT ... FOR UPDATE on a row that
 * does not exist yet locks nothing (and gap-locks, so two first-time claimers deadlock). clear() closes the active row when
 * StockRecovered arrives, keeping it as history.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->dedupe = app(Dedupe::class);
});

it('lets the first claim of a key through and holds every later one while the row is active', function () {
    expect($this->dedupe->claim('stock_out:11:4', 'stock_out'))->toBeTrue()
        ->and($this->dedupe->claim('stock_out:11:4', 'stock_out'))->toBeFalse()
        ->and($this->dedupe->claim('stock_out:11:4', 'stock_out'))->toBeFalse()
        ->and($this->dedupe->isActive('stock_out:11:4'))->toBeTrue()
        ->and($this->dedupe->isActive('stock_out:11:5'))->toBeFalse();

    $row = NotificationDedupe::query()->sole();
    expect($row->branch_id)->toBe($this->branch->id)
        ->and($row->dedupe_key)->toBe('stock_out:11:4')
        ->and($row->type)->toBe('stock_out')
        ->and($row->is_active)->toBe(1)
        ->and($row->cleared_at)->toBeNull()
        ->and($row->active_since)->not->toBeNull();
})->group('module:notifications', 'unit', 'feature');

it('clears the active row once, keeps it as history, and lets the next claim through', function () {
    $this->dedupe->claim('stock_out:11:4', 'stock_out');

    expect($this->dedupe->clear($this->branch->id, 'stock_out:11:4'))->toBeTrue()
        ->and($this->dedupe->clear($this->branch->id, 'stock_out:11:4'))->toBeFalse('nothing left to clear')
        ->and($this->dedupe->isActive('stock_out:11:4'))->toBeFalse()
        ->and($this->dedupe->claim('stock_out:11:4', 'stock_out'))->toBeTrue();

    $rows = NotificationDedupe::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->is_active)->toBeNull()
        ->and($rows[0]->cleared_at)->not->toBeNull()
        ->and($rows[1]->is_active)->toBe(1);
})->group('module:notifications', 'unit', 'feature');

it('clearing a key nobody claimed, or another branch key, changes nothing', function () {
    $this->dedupe->claim('stock_out:11:4', 'stock_out');
    $other = Branch::factory()->create();

    expect($this->dedupe->clear($this->branch->id, 'stock_out:99:99'))->toBeFalse()
        ->and($this->dedupe->clear($other->id, 'stock_out:11:4'))->toBeFalse()
        ->and($this->dedupe->isActive('stock_out:11:4'))->toBeTrue();
})->group('module:notifications', 'unit', 'feature');

it('keeps the same key apart in two branches', function () {
    $this->dedupe->claim('stock_out:11:4', 'stock_out');
    actInBranch(Branch::factory()->create());

    expect($this->dedupe->isActive('stock_out:11:4'))->toBeFalse()
        ->and($this->dedupe->claim('stock_out:11:4', 'stock_out'))->toBeTrue()
        ->and(DB::table('notification_dedupe')->where('is_active', 1)->count())->toBe(2);
})->group('module:notifications', 'unit', 'feature');

it('never locks a row that may not exist: no SELECT ... FOR UPDATE, and the claim is one insert that the unique key arbitrates', function () {
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    $this->dedupe->claim('stock_out:11:4', 'stock_out');
    $this->dedupe->claim('stock_out:11:4', 'stock_out');
    $this->dedupe->clear($this->branch->id, 'stock_out:11:4');

    foreach ($statements as $sql) {
        expect($sql)->not->toContain('for update', 'the first-row race: lock the unique key, not a missing row');
    }
    $inserts = array_filter($statements, fn (string $sql): bool => str_contains($sql, 'insert') && str_contains($sql, 'notification_dedupe'));
    expect($inserts)->toHaveCount(2, 'one attempted insert per claim, whether or not it won');
})->group('module:notifications', 'unit', 'feature');

it('needs a resolved branch to claim, and says so rather than guess', function () {
    app(CurrentBranch::class)->clear();

    expect(fn () => $this->dedupe->claim('stock_out:11:4', 'stock_out'))->toThrow(BranchNotResolved::class)
        ->and(fn () => $this->dedupe->isActive('stock_out:11:4'))->toThrow(BranchNotResolved::class);
})->group('module:notifications', 'unit', 'feature');
