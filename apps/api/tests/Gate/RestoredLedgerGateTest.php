<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\LedgerOracle;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Shifts\Contracts\HashChain;
use App\Shared\Models\Branch;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\SeededGate;

pest()->group('gate:p5:restored');

/*
 * The restore drill's proof on the seeded history (phase-5 plan 5.5b, sections 7 and 7.1; ADR 0020; checklist item backup-drill-ci). The
 * backup-drill CI job seeds the demo bakery with 14 days of history into `bakery` (SEED_PHASE=4), runs `backup:run`, then
 * `backup:drill --keep` into `bakery_drill`, and runs this group in the same Pest process as gate:p0 with
 *   GATE_SEED_DATABASE    the restored database (bakery_drill), opened by Tests\Support\SeededGate as the default connection;
 *   GATE_SEED_DAYS        the days of history the seed played (14);
 *   GATE_SOURCE_DATABASE  the database the dump was taken from (bakery), read on a connection of its own.
 * On the restored copy: the history is there (SeededGate), Inventory's LedgerOracle and the test suite's own ledger oracle agree, every
 * hash chain is whole, an append-only trigger refuses, the ledger accepts new movements and still adds up (inside a transaction that is
 * rolled back), and every table holds what the source holds, the ledger tables row for row. It fails, never skips.
 */

/** The database the dump was taken from, on the connection `gate_source`. */
function restoredGateSource(): Connection
{
    $database = trim((string) getenv('GATE_SOURCE_DATABASE'));
    if ($database === '') {
        Assert::fail('GATE_SOURCE_DATABASE must name the database the dump was taken from (the backup-drill job of .github/workflows/ci.yml sets it).');
    }
    $default = (string) config('database.default');
    config(['database.connections.gate_source' => ['database' => $database, 'url' => null] + (array) config("database.connections.{$default}")]);
    DB::purge('gate_source');

    return DB::connection('gate_source');
}

/** The rows of a table as sorted JSON lines, hashed: equal on two databases when they hold the same rows. */
function restoredGateDigest(Connection $db, string $table): string
{
    $lines = $db->table($table)->get()->map(fn (object $row): string => (string) json_encode((array) $row))->all();
    sort($lines);

    return hash('sha256', implode("\n", $lines));
}

it('restores the seeded history into a ledger whose two oracles agree and whose chains are whole', function () {
    $gate = SeededGate::open();

    $check = app(LedgerOracle::class)->check((string) config('database.default'));
    expect($check->toArray()['mismatches'])->toBe([])
        ->and($check->items)->toBeGreaterThan(0)
        ->and($check->locations)->toBeGreaterThan(0);

    $oracle = ledgerOracle($gate->branch->id);
    expect($oracle['movements'])->not->toBe([])
        ->and($oracle['balances'])->toBe($oracle['movements'], 'stock_balances != sum(movements) on the restored copy')
        ->and($oracle['view'])->toBe($oracle['movements'], 'item_stock_view != sum(movements) on the restored copy');

    $chains = app(HashChain::class)->verifyAll((string) config('database.default'));
    expect($chains->firstBroken())->toBeNull()
        ->and($chains->links)->toBeGreaterThan(0);

    fwrite(STDERR, sprintf("\n[gate:p5:restored] %s: ledger %d items in %d places agree, %d chains of %d links whole", $gate->database, $check->items, $check->locations, $chains->places, $chains->links));
});

it('keeps the append-only triggers on the restored copy, and its ledger takes new movements that still add up', function () {
    $gate = SeededGate::open();
    $id = DB::table('stock_movements')->min('id');
    expect($id)->not->toBeNull();

    DB::beginTransaction();
    try {
        $refused = null;
        try {
            DB::delete('DELETE FROM stock_movements WHERE id = ?', [$id]);
        } catch (QueryException $e) {
            $refused = (string) ($e->errorInfo[0] ?? $e->getCode());
        }
        expect($refused)->toBe('45000', 'the restored stock_movements refuses a DELETE by its trigger');

        $branch = actInBranch(Branch::factory()->create());
        withActor(User::factory()->create(['branch_id' => $branch->id])->id);
        $item = Item::factory()->ingredient('g')->create(['branch_id' => $branch->id])->id;
        $store = stockLocation($branch, LocationType::Store)->id;
        app(Ledger::class)->appendMany([MovementIntent::of($item, $store, '12.5000', MovementReason::PurchaseIn, 'restored-gate')]);
        app(Ledger::class)->appendMany([MovementIntent::of($item, $store, '-2.5000', MovementReason::Waste, 'restored-gate')]);

        expect(stockRow($item, $store)?->on_hand)->toBe('10.0000')
            ->and(app(LedgerOracle::class)->check((string) config('database.default'))->ok())->toBeTrue();
    } finally {
        DB::rollBack();
    }
    actInBranch($gate->branch);
});

it('holds every table of the source, row for row in the ledger, the chains and the money', function () {
    SeededGate::open();
    $restored = DB::connection();
    $source = restoredGateSource();
    // written by the jobs themselves after the dump (the backup's and the drill's own rows) or by the stack's drivers
    $moving = ['ops_runs', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions'];

    $tables = array_values(array_diff($source->getSchemaBuilder()->getTableListing($source->getDatabaseName(), false), $moving));
    expect($restored->getSchemaBuilder()->getTableListing($restored->getDatabaseName(), false))->toContain(...$tables);
    $counted = 0;
    foreach ($tables as $table) {
        expect($restored->table($table)->count())->toBe($source->table($table)->count(), "{$table}: the restored copy holds another number of rows");
        $counted++;
    }
    foreach (['stock_movements', 'stock_balances', 'stock_counts', 'stock_count_lines', 'orders', 'payments', 'audit_log'] as $table) {
        expect(restoredGateDigest($restored, $table))->toBe(restoredGateDigest($source, $table), "{$table}: the restored rows differ from the source's");
    }
    fwrite(STDERR, sprintf("\n[gate:p5:restored] %d tables hold the source's rows; 7 ledger, chain and money tables row for row", $counted));
});
