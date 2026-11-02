<?php

declare(strict_types=1);

namespace App\Ops\Backup;

use App\Ops\Models\OpsRun;
use App\Ops\OpsFailure;
use App\Ops\Runs;
use App\Shared\Events\BackupFailed;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One backup (`php artisan backup:run`, nightly at 02:30 Addis Ababa time; phase-5 plan 5.5b; ADR 0020), in this order:
 *   1. an ops_runs row `running`; the folder resolved (BACKUP_PATH, outside the app) and what a killed run left there removed;
 *   2. the row counts of the main tables and the triggers, read just before the dump;
 *   3. mysqldump of the app's database, gzipped as it streams, as `.partial`, the credentials in a 0600 option file (MysqlClient);
 *   4. the counts again (a table that changed during the dump gets a range in the manifest), then the files folder as hard links;
 *   5. the dump renamed into place, its sha256 and size, the manifest written last (from then on it is a good backup);
 *   6. retention (30 dumps, the cap, never the newest good one), then the off-site copy when BACKUP_REMOTE is set (off by default);
 *   7. the row `ok` with its size, checksum, file name and detail.
 * Any failure removes this night's files, closes the row `failed` with its key, and raises BackupFailed; the older dumps stay.
 */
final class BackupRunner
{
    public function __construct(
        private readonly BackupConfig $config,
        private readonly MysqlClient $client,
        private readonly FilesSnapshot $files,
        private readonly Offsite $offsite,
        private readonly Runs $runs,
    ) {}

    public function run(): OpsRun
    {
        $run = $this->runs->start(OpsRun::BACKUP);
        $folder = null;
        $dumpName = null;

        try {
            $connection = DB::connection();
            if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                throw OpsFailure::because('backup.unsupported_driver', ['driver' => $connection->getDriverName()]);
            }
            $folder = new BackupFolder($this->config->folder());
            $folder->removePartials();
            $dumpName = $folder->dumpName(BackupFolder::stamp($run->started_at));

            $tables = $this->countedTables($connection);
            $before = $this->counts($connection, $tables);
            $triggers = $this->triggers($connection);
            $migrations = $connection->table('migrations')->count();

            $partial = $folder->dumpPath($dumpName).'.partial';
            $this->client->dump($connection, $partial, $folder->path);
            $after = $this->counts($connection, $tables);

            $filesPartial = $folder->filesPath($dumpName).'.partial';
            $files = $this->files->take($filesPartial);
            if (is_dir($filesPartial) && ! rename($filesPartial, $folder->filesPath($dumpName))) {
                throw OpsFailure::because('backup.files_failed');
            }
            if (! rename($partial, $folder->dumpPath($dumpName))) {
                throw OpsFailure::because('backup.write_failed');
            }
            $sha256 = (string) hash_file('sha256', $folder->dumpPath($dumpName));
            $bytes = (int) filesize($folder->dumpPath($dumpName));
            $manifest = new Manifest(
                $dumpName, $sha256, $bytes, $run->started_at->toIso8601ZuluString(), $this->commit(), $migrations,
                $before, array_diff_assoc($after, $before), $triggers, $files,
            );
            if (! $manifest->write($folder->manifestPath($dumpName))) {
                throw OpsFailure::because('backup.write_failed');
            }

            $retention = Retention::plan($folder->dumps(), $this->config->keep(), $this->config->capBytes());
            foreach ($retention['delete'] as $old) {
                $folder->remove($old);
            }
            $offsite = $this->offsite->copy($folder, $dumpName);

            return $this->runs->finish($run, OpsRun::OK, [
                'app_commit' => $manifest->appCommit,
                'migrations' => $migrations,
                'rows' => $before,
                'triggers' => count($triggers),
                'files' => $files,
                'kept' => $retention['kept'],
                'kept_bytes' => $retention['kept_bytes'],
                'deleted' => count($retention['delete']),
                'over_cap' => $retention['over_cap'],
                'offsite' => $offsite?->status,
            ], $bytes, $sha256, $dumpName);
        } catch (Throwable $e) {
            $failure = $e instanceof OpsFailure ? $e : OpsFailure::because('backup.unexpected', [], $e);
            if (! $e instanceof OpsFailure) {
                Log::error('ops: backup:run failed', ['exception' => $e]);
            }
            if ($folder !== null && $dumpName !== null) {
                $folder->remove($dumpName);
                $folder->removePartials();
            }
            $this->runs->finish($run, OpsRun::FAILED, ['failure_key' => $failure->key] + $failure->detail);
            event(new BackupFailed($run->id, OpsRun::BACKUP, $failure->key, $this->lastGoodAgeHours()));

            return $run;
        }
    }

    /** @return list<string> the main tables (config backup.main_tables) that exist in this database */
    private function countedTables(Connection $connection): array
    {
        $present = $connection->getSchemaBuilder()->getTableListing($connection->getDatabaseName(), false);

        return array_values(array_intersect($this->config->mainTables(), $present));
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function counts(Connection $connection, array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = $connection->table($table)->count();
        }

        return $counts;
    }

    /** @return list<string> */
    private function triggers(Connection $connection): array
    {
        return array_values(array_map(
            static fn (mixed $row): string => (string) ((array) $row)['name'],
            $connection->select('SELECT trigger_name AS name FROM information_schema.triggers WHERE trigger_schema = DATABASE() ORDER BY trigger_name'),
        ));
    }

    /** The commit of the release that runs (the REVISION file the deploy writes), or null outside a release. */
    private function commit(): ?string
    {
        $file = base_path('REVISION');
        $revision = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return preg_match('/^[0-9a-f]{7,40}$/', $revision) === 1 ? $revision : null;
    }

    private function lastGoodAgeHours(): ?int
    {
        $last = OpsRun::query()->where('kind', OpsRun::BACKUP)->where('status', OpsRun::OK)->orderByDesc('id')->first();
        $at = $last?->finished_at;

        return $at === null ? null : (int) $at->diffInHours(CarbonImmutable::now('UTC'), true);
    }
}
