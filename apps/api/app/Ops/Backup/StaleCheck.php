<?php

declare(strict_types=1);

namespace App\Ops\Backup;

use App\Ops\Models\OpsRun;
use App\Shared\Events\BackupStale;
use Carbon\CarbonImmutable;

/**
 * Is the newest good backup too old? (phase-5 plan section 5, step 5.5c; ADR 0020.) Read from ops_runs only, never the folder: the last
 * `ok` backup run is stale once it finished more than backup.stale_hours (26) ago, and the period it opens is named by that run, so the
 * hourly check raising it again is the same period and Notifications' gate tells each admin once. While no backup has ever ended ok, the
 * period is run 0, counted from the first backup that was tried; before any backup was tried nothing is stale (the System page says
 * "no backup yet", and the scheduler heartbeat is what shows a scheduler that never ran).
 */
final class StaleCheck
{
    public const string FAILURE_KEY = 'backup.stale';

    public function __construct(private readonly BackupConfig $config) {}

    /** The BackupStale to raise now, or null while the newest good backup is young enough or no backup was ever tried. */
    public function check(?CarbonImmutable $now = null): ?BackupStale
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone('UTC');
        $limit = $now->subHours($this->config->staleHours());

        $last = OpsRun::query()->where('kind', OpsRun::BACKUP)->where('status', OpsRun::OK)->whereNotNull('finished_at')->orderByDesc('id')->first();
        $finished = $last?->finished_at;
        if ($last !== null && $finished !== null) {
            return $finished->lessThan($limit)
                ? new BackupStale((int) $last->id, OpsRun::BACKUP, self::FAILURE_KEY, (int) $finished->diffInHours($now, true), $now)
                : null;
        }

        $first = OpsRun::query()->where('kind', OpsRun::BACKUP)->orderBy('id')->first();
        $started = $first?->started_at;

        return $started !== null && $started->lessThan($limit) ? new BackupStale(0, OpsRun::BACKUP, self::FAILURE_KEY, null, $now) : null;
    }
}
