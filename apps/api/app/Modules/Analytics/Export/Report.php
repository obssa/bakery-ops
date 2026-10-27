<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Export;

use App\Shared\Time\LocalDay;

/**
 * An analytics export as it is built from the summaries (Reports, step 4.4d) before it is written: the report's name, its range, the
 * language it is written in, the freshness of the range (as_of: the oldest rebuild of its built days, ISO 8601 UTC or null; stale_days:
 * the days not built), its tables (the dashboard's own first, then its rows day by day), the totals a PDF prints above its table and that
 * table (the first one, a long range of sales or shifts grouped by week or month, only the columns a page has room for).
 */
final readonly class Report
{
    /**
     * @param  array{as_of: string|null, stale_days: list<string>}  $freshness
     * @param  list<Sheet>  $sheets
     * @param  list<array{0: string, 1: string, 2: string|int|null}>  $totals  [column key, type, value]
     */
    public function __construct(
        public string $name,
        public LocalDay $range,
        public string $timezone,
        public string $locale,
        public array $freshness,
        public array $sheets,
        public array $totals,
        public Sheet $pdf,
    ) {}

    /** The rows a file of $format holds: every table of an xlsx, the first one of a CSV or a PDF; header rows and About not counted. */
    public function rows(string $format): int
    {
        if ($format !== 'xlsx') {
            return $this->sheets[0]->count;
        }

        return array_sum(array_map(static fn (Sheet $sheet): int => $sheet->count, $this->sheets));
    }
}
