<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * A restore drill did not pass (phase-5 plan section 5, step 5.5b; ADR 0020): raised by App\Ops when `backup:drill` fails, with the
 * ops_runs row, the first failure key and how many mismatches the checks found (the ledger pairs, the broken links, the missing or
 * silent triggers and the row counts, as one number). Host-wide, so it carries no branch. Notifications tells every admin from step
 * 5.5c (NotifyOps). The payload is ids, keys and numbers, never a path, a host or a token.
 */
final class DrillFailed
{
    public readonly CarbonImmutable $occurredAt;

    public function __construct(
        public readonly int $runId,
        public readonly string $failureKey,
        public readonly int $mismatches,
        ?CarbonImmutable $occurredAt = null,
    ) {
        $this->occurredAt = ($occurredAt ?? CarbonImmutable::now())->setTimezone('UTC');
    }

    /**
     * @return array{run_id: int, failure_key: string, mismatches: int}
     */
    public function payload(): array
    {
        return ['run_id' => $this->runId, 'failure_key' => $this->failureKey, 'mismatches' => $this->mismatches];
    }
}
