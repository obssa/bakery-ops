<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Contracts;

/**
 * What one Rebuild::day wrote (phase-4 plan section 6, 4.4a): the rows per summary table, the day's new version, whether the figures
 * changed since the run before, how long it took, and the day's fingerprint. Plain values.
 */
final readonly class RebuildResult
{
    /**
     * @param  array<string, int>  $rows  table => rows written
     */
    public function __construct(
        public int $branchId,
        public string $day,
        public string $trigger,
        public array $rows,
        public int $version,
        public bool $changed,
        public int $durationMs,
        public string $fingerprint,
    ) {}

    /**
     * @return array{day: string, trigger: string, rows: array<string, int>, version: int, changed: bool, duration_ms: int, fingerprint: string}
     */
    public function toArray(): array
    {
        return [
            'day' => $this->day,
            'trigger' => $this->trigger,
            'rows' => $this->rows,
            'version' => $this->version,
            'changed' => $this->changed,
            'duration_ms' => $this->durationMs,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
