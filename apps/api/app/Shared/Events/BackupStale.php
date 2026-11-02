<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * The newest good backup is too old (phase-5 plan section 5, step 5.5c; ADR 0020): raised by App\Ops's hourly `backup:check-stale` while
 * the last `ok` backup is older than backup.stale_hours (26). The run names the stale period: the ops_runs row of that last good backup,
 * or 0 while no backup has ever ended ok (counted from the first try). The check raises it every hour the period lasts; Notifications'
 * NotifyOps tells every admin once per period through the delivery gate backup_stale:{run_id} (the stock alerts' gate, ADR 0011), so a
 * lost job heals within the hour and nobody is told twice. Host-wide, so it carries no branch. The payload is ids, keys and numbers,
 * never a path, a host or a token.
 */
final class BackupStale
{
    public readonly CarbonImmutable $occurredAt;

    public function __construct(
        public readonly int $runId,
        public readonly string $kind,
        public readonly string $failureKey,
        public readonly ?int $ageH,
        ?CarbonImmutable $occurredAt = null,
    ) {
        $this->occurredAt = ($occurredAt ?? CarbonImmutable::now())->setTimezone('UTC');
    }

    /**
     * @return array{run_id: int, kind: string, failure_key: string, age_h: int|null}
     */
    public function payload(): array
    {
        return ['run_id' => $this->runId, 'kind' => $this->kind, 'failure_key' => $this->failureKey, 'age_h' => $this->ageH];
    }
}
