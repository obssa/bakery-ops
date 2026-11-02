<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * A backup did not end `ok` (phase-5 plan section 5, step 5.5b; ADR 0020): raised by App\Ops when `backup:run` fails, with the
 * ops_runs row, the kind of run (`backup`), the failure key and how many hours old the last good backup is (null when there is none).
 * Host-wide, so it carries no branch: one host, one database. Notifications tells every admin from step 5.5c (NotifyOps); until then the
 * event is the record's echo only. The payload is ids, keys and numbers, never a path, a host or a token.
 */
final class BackupFailed
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
