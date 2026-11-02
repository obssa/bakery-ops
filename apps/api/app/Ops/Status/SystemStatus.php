<?php

declare(strict_types=1);

namespace App\Ops\Status;

use App\Modules\Identity\Contracts\DeviceCensus;
use App\Ops\Backup\BackupConfig;
use App\Ops\Backup\BackupFolder;
use App\Ops\Models\OpsRun;
use App\Ops\SchedulerTick;
use App\Shared\Files\StoredFile;
use App\Shared\Observability\ErrorTracking;
use App\Shared\Tenancy\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * What the System page shows (phase-5 plan 4.4, step 5.5c; ADR 0020 and 0021): one array read from what the host already keeps.
 *   app            the release's commit (the REVISION file the deploy writes), when that file was written, the migrations that ran;
 *   scheduler      the last ops.scheduler.tick and whether it is at most 3 minutes old;
 *   queue          the jobs waiting and the oldest one's age (the database queue's table; another driver gives its size only), the failed
 *                  jobs of the last 24 hours and in all (the daily prune keeps 30 days, plan 10.2 D7);
 *   backups        from ops_runs: the last good backup (when, how big, how many hours old), the status of the last backup run, the last
 *                  drill (when it ended and its status: `skipped` until the scratch database exists), and from the backup folder the good
 *                  dumps kept and the MiB the dumps and manifests take (read only if the folder already exists: a read creates nothing);
 *   files          the MiB the stored receipt photos take, all branches (one disk holds them all);
 *   devices        the branch's devices: enrolled, seen in the last 15 minutes, revoked (Identity's DeviceCensus);
 *   flags          the rollout switches the server's .env decides;
 *   error_tracking whether a DSN is set (never the DSN).
 * Never a path, a key, a password or a host name: names and folders stay on the host, sizes are whole MiB from integer bytes, times are
 * UTC ISO 8601.
 */
final class SystemStatus
{
    private const int MIB = 1048576;

    /** $revisionFile: the file the deploy writes the release's commit in; empty means the app's own REVISION (a test names another). */
    public function __construct(
        private readonly BackupConfig $backups,
        private readonly DeviceCensus $devices,
        private readonly string $revisionFile = '',
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function read(): array
    {
        $now = CarbonImmutable::now('UTC');

        return [
            'app' => $this->app(),
            'scheduler' => $this->scheduler($now),
            'queue' => $this->queue($now),
            'backups' => $this->backups($now),
            'files' => ['used_mb' => self::mib((int) StoredFile::query()->withoutGlobalScope(BranchScope::class)->sum('bytes'))],
            'devices' => $this->devices(),
            'flags' => [
                'require_enrolled_device' => (bool) config('identity.require_enrolled_device'),
                'require_shift_for_cash' => (bool) config('payments.require_shift_for_cash'),
                'require_shift_for_station_locations' => (bool) config('ledger.require_shift_for_station_locations'),
                'open_ad_hoc_on_accept' => (bool) config('shifts.open_ad_hoc_on_accept'),
            ],
            'error_tracking' => ['enabled' => ErrorTracking::enabled()],
        ];
    }

    /** Whole MiB, rounded half up, from integer bytes (no float). */
    public static function mib(int $bytes): int
    {
        return intdiv(max(0, $bytes) + intdiv(self::MIB, 2), self::MIB);
    }

    /**
     * @return array{commit: string|null, released_at: string|null, migrations: int}
     */
    private function app(): array
    {
        $file = $this->revisionFile !== '' ? $this->revisionFile : base_path('REVISION');
        clearstatcache(true, $file); // a deploy replaces the file under a long-lived worker
        $revision = is_file($file) ? trim((string) file_get_contents($file)) : '';
        $commit = preg_match('/^[0-9a-f]{7,40}$/', $revision) === 1 ? $revision : null;
        $written = $commit === null ? false : filemtime($file);

        return [
            'commit' => $commit,
            'released_at' => $written === false ? null : CarbonImmutable::createFromTimestampUTC($written)->toIso8601ZuluString(),
            'migrations' => DB::table((string) config('database.migrations.table', 'migrations'))->count(),
        ];
    }

    /**
     * @return array{last_tick_at: string|null, ok: bool}
     */
    private function scheduler(CarbonImmutable $now): array
    {
        $tick = SchedulerTick::last();

        return [
            'last_tick_at' => $tick?->toIso8601ZuluString(),
            'ok' => $tick !== null && $tick->diffInSeconds($now, false) <= SchedulerTick::FRESH_SECONDS,
        ];
    }

    /**
     * @return array{pending: int, oldest_age_s: int|null, failed_24h: int, failed_total: int}
     */
    private function queue(CarbonImmutable $now): array
    {
        $connection = (string) config('queue.default');
        $settings = config("queue.connections.{$connection}");
        $settings = is_array($settings) ? $settings : [];
        if (($settings['driver'] ?? null) === 'database') {
            $jobs = DB::connection(is_string($settings['connection'] ?? null) ? $settings['connection'] : null)->table((string) ($settings['table'] ?? 'jobs'));
            $pending = $jobs->count();
            $oldest = $pending === 0 ? null : $jobs->min('created_at');
            $oldestAge = is_numeric($oldest) ? max(0, $now->getTimestamp() - (int) $oldest) : null;
        } else {
            $pending = Queue::connection($connection)->size();
            $oldestAge = null;
        }

        $failedTable = (string) config('queue.failed.table', 'failed_jobs');
        $failed = DB::connection(is_string(config('queue.failed.database')) ? (string) config('queue.failed.database') : null)->table($failedTable);

        return [
            'pending' => $pending,
            'oldest_age_s' => $oldestAge,
            'failed_24h' => (clone $failed)->where('failed_at', '>=', $now->subDay()->format('Y-m-d H:i:s'))->count(),
            'failed_total' => $failed->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backups(CarbonImmutable $now): array
    {
        $lastOk = OpsRun::query()->where('kind', OpsRun::BACKUP)->where('status', OpsRun::OK)->orderByDesc('id')->first();
        $lastRun = OpsRun::query()->where('kind', OpsRun::BACKUP)->orderByDesc('id')->first();
        $drill = OpsRun::query()->where('kind', OpsRun::DRILL)->orderByDesc('id')->first();
        $okAt = $lastOk?->finished_at;

        $kept = 0;
        $bytes = 0;
        $path = $this->backups->existingFolder();
        if ($path !== null) {
            $folder = new BackupFolder($path);
            $kept = count(array_filter($folder->dumps(), static fn (array $dump): bool => $dump['good']));
            $bytes = $folder->dumpBytes();
        }

        return [
            'last_ok_at' => $okAt?->toIso8601ZuluString(),
            'last_status' => $lastRun?->status,
            'bytes' => $lastOk?->bytes,
            'age_h' => $okAt === null ? null : (int) $okAt->diffInHours($now, true),
            'kept' => $kept,
            'disk_used_mb' => self::mib($bytes),
            'drill' => [
                'last_at' => $drill === null ? null : ($drill->finished_at ?? $drill->started_at)->toIso8601ZuluString(),
                'status' => $drill?->status,
            ],
        ];
    }

    /**
     * @return array{enrolled: int, seen_15m: int, revoked: int}
     */
    private function devices(): array
    {
        $count = $this->devices->count(900);

        return ['enrolled' => $count->enrolled, 'seen_15m' => $count->seenRecently, 'revoked' => $count->revoked];
    }
}
