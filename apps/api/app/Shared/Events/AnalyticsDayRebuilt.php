<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A local day of a branch was rebuilt (phase-4 plan section 5; Analytics\Contracts\Rebuild::day, step 4.4a; ADR 0014): its summary
 * rows were replaced and its analytics_days row now holds `version`; `changed` says whether the figures differ from the run before.
 * Dispatched once the rebuild has committed, never for one that rolled back. No listener: the dashboards' freshness feed reads
 * analytics_days (step 4.4b); the event is there for tests and for whatever wants to know later.
 */
final class AnalyticsDayRebuilt extends DomainEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        int $branchId,
        public readonly string $day,
        public readonly string $trigger,
        public readonly int $version,
        public readonly bool $changed,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{day: string, trigger: string, version: int, changed: bool}
     */
    public function payload(): array
    {
        return [
            'day' => $this->day,
            'trigger' => $this->trigger,
            'version' => $this->version,
            'changed' => $this->changed,
        ];
    }
}
