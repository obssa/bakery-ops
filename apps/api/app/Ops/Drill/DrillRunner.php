<?php

declare(strict_types=1);

namespace App\Ops\Drill;

use App\Modules\Inventory\Contracts\LedgerOracle;
use App\Modules\Shifts\Contracts\HashChain;
use App\Ops\Backup\BackupConfig;
use App\Ops\Backup\BackupFolder;
use App\Ops\Backup\Manifest;
use App\Ops\Backup\MysqlClient;
use App\Ops\Models\OpsRun;
use App\Ops\OpsFailure;
use App\Ops\Runs;
use App\Shared\Events\DrillFailed;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The restore drill (`php artisan backup:drill`, Sundays at 03:30 Addis Ababa time; phase-5 plan 5.5b; ADR 0020): it proves the newest
 * good dump restores into a working ledger, then leaves nothing behind.
 *   - BACKUP_DRILL_DATABASE unset: a `skipped` row (drill.not_configured), nothing else. It must never be the app's own database.
 *   - The newest dump whose manifest is good, its sha256 checked; the scratch database emptied and the dump restored into it with
 *     the mysql client (the credentials in a 0600 option file).
 *   - On the connection `backup_drill`: Inventory's LedgerOracle (movements = stock_balances = item_stock_view), Shifts' HashChain over
 *     every chain, the manifest's triggers present, every append-only table's UPDATE and DELETE triggers present and refusing a
 *     statement (inside a transaction that is rolled back), and every counted table's rows within the manifest's range.
 *   - The scratch database emptied again (unless --keep: the CI job reads it afterwards, and a person restoring by hand may want it),
 *     the row closed `ok` or `failed` with every failure key, and DrillFailed raised on a failure.
 * The app's own database is only read (to name it); nothing in the drill writes there but its ops_runs row.
 */
final class DrillRunner
{
    public const string CONNECTION = 'backup_drill';

    /** The append-only tables (scripts/check_invariants.py APPEND_ONLY_TABLES; AppendOnlyListTest keeps the two equal). */
    public const array APPEND_ONLY = [
        'stock_movements', 'audit_log', 'order_events', 'item_cost_history', 'handover_notes', 'payment_refunds',
        'stock_movement_reversals', 'order_refunds', 'order_refund_lines',
    ];

    public function __construct(
        private readonly BackupConfig $config,
        private readonly MysqlClient $client,
        private readonly Runs $runs,
        private readonly LedgerOracle $oracle,
        private readonly HashChain $chain,
    ) {}

    public function run(bool $keep = false): OpsRun
    {
        $database = $this->config->drillDatabase();
        if ($database === null) {
            return $this->runs->skipped(OpsRun::DRILL, ['reason_key' => 'drill.not_configured']);
        }

        $run = $this->runs->start(OpsRun::DRILL);
        $target = null;
        $detail = [];
        $failures = [];
        $mismatches = 0;
        $dumpName = null;
        try {
            $source = DB::connection();
            if (! in_array($source->getDriverName(), ['mysql', 'mariadb'], true)) {
                throw OpsFailure::because('drill.unsupported_driver', ['driver' => $source->getDriverName()]);
            }
            if (strcasecmp($database, (string) $source->getDatabaseName()) === 0) {
                throw OpsFailure::because('drill.same_database');
            }
            $folder = new BackupFolder($this->config->folder());
            [$dumpName, $manifest] = $folder->newestGood() ?? throw OpsFailure::because('drill.no_dump');
            if (hash_file('sha256', $folder->dumpPath($dumpName)) !== $manifest->sha256) {
                throw OpsFailure::because('drill.checksum_mismatch');
            }

            $target = $this->connect($database);
            $this->empty($target, $database);
            $this->client->restore($folder->dumpPath($dumpName), $target, $folder->path);
            [$failures, $mismatches, $detail] = $this->check($target, $manifest);
        } catch (Throwable $e) {
            $failure = $e instanceof OpsFailure ? $e : OpsFailure::because('drill.unexpected', [], $e);
            if (! $e instanceof OpsFailure) {
                Log::error('ops: backup:drill failed', ['exception' => $e]);
            }
            $failures[] = $failure->key;
            $detail += $failure->detail;
        } finally {
            if ($target !== null && ! $keep) {
                try {
                    $this->empty($target, $database);
                } catch (Throwable $e) {
                    Log::error('ops: backup:drill could not empty the scratch database', ['exception' => $e]);
                    $detail['cleanup_failed'] = true;
                }
            }
            DB::purge(self::CONNECTION);
        }

        $detail = ['dump' => $dumpName, 'kept' => $keep && $target !== null] + $detail;
        if ($failures === []) {
            return $this->runs->finish($run, OpsRun::OK, $detail, pathHint: $dumpName);
        }
        $this->runs->finish($run, OpsRun::FAILED, ['failure_key' => $failures[0], 'failures' => $failures] + $detail, pathHint: $dumpName);
        event(new DrillFailed($run->id, $failures[0], $mismatches));

        return $run;
    }

    /**
     * Every check of the restored database; each failing one adds its key.
     *
     * @return array{0: list<string>, 1: int, 2: array<string, mixed>}
     */
    private function check(Connection $target, Manifest $manifest): array
    {
        $failures = [];
        $mismatches = 0;

        $ledger = $this->oracle->check(self::CONNECTION);
        if (! $ledger->ok()) {
            $failures[] = 'drill.ledger_mismatch';
            $mismatches += count($ledger->mismatches);
        }

        $chains = $this->chain->verifyAll(self::CONNECTION);
        if (! $chains->ok()) {
            $failures[] = 'drill.chain_broken';
            $mismatches += count($chains->failures);
        }

        $triggers = $this->triggers($target, $manifest);
        if ($triggers['missing'] !== []) {
            $failures[] = 'drill.triggers_missing';
            $mismatches += count($triggers['missing']);
        }
        if ($triggers['silent'] !== []) {
            $failures[] = 'drill.trigger_silent';
            $mismatches += count($triggers['silent']);
        }

        $rows = $this->rows($target, $manifest);
        if ($rows['off'] !== []) {
            $failures[] = 'drill.row_counts';
            $mismatches += count($rows['off']);
        }

        return [$failures, $mismatches, [
            'ledger' => ['items' => $ledger->items, 'locations' => $ledger->locations, 'mismatches' => count($ledger->mismatches), 'first' => array_slice($ledger->mismatches, 0, 5)],
            'chains' => ['places' => $chains->places, 'links' => $chains->links, 'failures' => count($chains->failures), 'first_broken' => $chains->firstBroken()],
            'triggers' => $triggers,
            'rows' => $rows,
        ]];
    }

    /**
     * The manifest's triggers exist; every append-only table has its UPDATE and DELETE triggers and they refuse a statement.
     *
     * @return array{expected: int, present: int, probed: int, missing: list<string>, silent: list<string>}
     */
    private function triggers(Connection $target, Manifest $manifest): array
    {
        $present = [];
        foreach ($target->select('SELECT trigger_name AS name, event_object_table AS tbl, event_manipulation AS event FROM information_schema.triggers WHERE trigger_schema = DATABASE()') as $row) {
            $row = (array) $row;
            $present[(string) $row['name']] = [(string) $row['tbl'], strtoupper((string) $row['event'])];
        }
        $missing = array_values(array_diff($manifest->triggers, array_keys($present)));
        $silent = [];
        $probed = 0;
        $tables = $target->getSchemaBuilder()->getTableListing($target->getDatabaseName(), false);
        foreach (self::APPEND_ONLY as $table) {
            if (! in_array($table, $tables, true)) {
                continue;
            }
            $events = array_map(static fn (array $t): string => $t[1], array_filter($present, static fn (array $t): bool => $t[0] === $table));
            $armed = [];
            foreach (['UPDATE', 'DELETE'] as $event) {
                if (in_array($event, $events, true)) {
                    $armed[] = $event;
                } else {
                    $missing[] = "{$table}:{$event}"; // reported missing, not probed: a statement there would only change the row
                }
            }
            $id = $target->table($table)->min('id');
            if ($id === null || $armed === []) {
                continue; // nothing to refuse: the triggers' presence is all an empty table can show
            }
            $probed++;
            $target->beginTransaction();
            try {
                if (in_array('UPDATE', $armed, true) && ! $this->refused(fn () => $target->update("UPDATE {$table} SET id = id WHERE id = ?", [$id]))) {
                    $silent[] = "{$table}:UPDATE";
                }
                if (in_array('DELETE', $armed, true) && ! $this->refused(fn () => $target->delete("DELETE FROM {$table} WHERE id = ?", [$id]))) {
                    $silent[] = "{$table}:DELETE";
                }
            } finally {
                $target->rollBack();
            }
        }

        return ['expected' => count($manifest->triggers), 'present' => count($present), 'probed' => $probed, 'missing' => $missing, 'silent' => $silent];
    }

    /** A trigger refuses with SIGNAL SQLSTATE '45000'; any other outcome (the row changed, another error) is not its refusal. */
    private function refused(callable $statement): bool
    {
        try {
            $statement();
        } catch (QueryException $e) {
            return (string) ($e->errorInfo[0] ?? $e->getCode()) === '45000';
        }

        return false;
    }

    /**
     * Every counted table holds between the manifest's two counts, and the migrations are the manifest's.
     *
     * @return array{tables: int, off: array<string, array{restored: int, expected: array{0: int, 1: int}}>}
     */
    private function rows(Connection $target, Manifest $manifest): array
    {
        $off = [];
        $expected = ['migrations' => [$manifest->migrations, $manifest->migrations]];
        foreach (array_keys($manifest->rows) as $table) {
            $range = $manifest->rowRange($table);
            if ($range !== null) {
                $expected[$table] = $range;
            }
        }
        foreach ($expected as $table => [$low, $high]) {
            $restored = $target->getSchemaBuilder()->hasTable($table) ? $target->table($table)->count() : -1;
            if ($restored < $low || $restored > $high) {
                $off[$table] = ['restored' => $restored, 'expected' => [$low, $high]];
            }
        }

        return ['tables' => count($expected), 'off' => $off];
    }

    private function connect(string $database): Connection
    {
        $default = (string) config('database.default');
        $config = (array) config("database.connections.{$default}");
        $config['database'] = $database;
        $config['url'] = null;
        config(['database.connections.'.self::CONNECTION => $config]);
        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }

    /**
     * Drop every view and table of the scratch database (their triggers go with them), after making sure the connection is on it and it
     * is not the app's. The app has no stored routines; a dump that brings one also drops it before creating it.
     */
    private function empty(Connection $target, string $database): void
    {
        $current = (string) $target->scalar('SELECT DATABASE()');
        if (strcasecmp($current, $database) !== 0 || strcasecmp($current, (string) DB::connection()->getDatabaseName()) === 0) {
            throw OpsFailure::because('drill.same_database');
        }
        $schema = $target->getSchemaBuilder();
        $schema->dropAllViews();
        $schema->dropAllTables();
    }
}
