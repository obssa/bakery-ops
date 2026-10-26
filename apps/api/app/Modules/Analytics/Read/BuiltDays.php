<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The analytics_days rows of a span of local days of one branch (phase-4 plan section 4.6, step 4.4c): which days are built, at which
 * version and when. One query on the table's primary key. A dashboard's ETag is made of every version and rebuilt_at here (a day deleted
 * and rebuilt starts again at version 1, so the instant goes in too), and its freshness of the days of each period: as_of, the oldest
 * rebuilt_at of the period's built days (ISO 8601 UTC, null when none is built), and stale_days, the days with no row (not built, never
 * counted as zero; R-U).
 */
final readonly class BuiltDays
{
    /**
     * @param  array<string, array{version: int, rebuilt_at: string}>  $days
     */
    private function __construct(private array $days) {}

    public static function read(int $branchId, string $from, string $to): self
    {
        $days = [];
        foreach (DB::table('analytics_days')->where('branch_id', $branchId)->whereBetween('day', [$from, $to])->orderBy('day')->get(['day', 'version', 'rebuilt_at']) as $row) {
            $days[substr((string) $row->day, 0, 10)] = ['version' => (int) $row->version, 'rebuilt_at' => (string) $row->rebuilt_at];
        }

        return new self($days);
    }

    public function has(string $day): bool
    {
        return isset($this->days[$day]);
    }

    /**
     * How many of $days are built.
     *
     * @param  list<string>  $days
     */
    public function count(array $days): int
    {
        return count(array_filter($days, fn (string $day): bool => $this->has($day)));
    }

    /** Every built day with its version and rebuilt_at, for an ETag. */
    public function versions(): string
    {
        return implode(',', array_map(static fn (string $day, array $row): string => $day.':'.$row['version'].':'.$row['rebuilt_at'], array_keys($this->days), $this->days));
    }

    /**
     * @return array{as_of: string|null, stale_days: list<string>}
     */
    public function freshness(LocalDay $period): array
    {
        $oldest = null;
        $stale = [];
        foreach ($period->days() as $day) {
            if (! isset($this->days[$day])) {
                $stale[] = $day;

                continue;
            }
            $at = $this->days[$day]['rebuilt_at'];
            $oldest = $oldest === null || $at < $oldest ? $at : $oldest;
        }

        return [
            'as_of' => $oldest === null ? null : CarbonImmutable::parse($oldest, 'UTC')->toIso8601ZuluString(),
            'stale_days' => $stale,
        ];
    }
}
