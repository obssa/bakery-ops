<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The points of a dashboard series (phase-4 plan section 4.6, step 4.4c): a range of local days cut into days (key YYYY-MM-DD), weeks
 * that start on Monday (key: the Monday's date, even when the range starts later in the week) or calendar months (key YYYY-MM). The
 * first and the last point are cut at the range's ends; every point lists its days. Days are calendar dates, so no timezone arithmetic
 * is needed here: the range already holds the branch's local dates. Pure.
 */
final class Buckets
{
    public const array GROUPS = ['day', 'week', 'month'];

    /**
     * @return list<array{key: string, from: string, to: string, days: list<string>}>
     */
    public static function of(LocalDay $range, string $group): array
    {
        if (! in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException("Buckets: no group '{$group}'");
        }
        $buckets = [];
        $cursor = CarbonImmutable::parse($range->from, 'UTC');
        $end = CarbonImmutable::parse($range->to, 'UTC');
        while ($cursor->lessThanOrEqualTo($end)) {
            $day = $cursor->toDateString();
            $key = match ($group) {
                'day' => $day,
                'week' => $cursor->subDays($cursor->dayOfWeekIso - 1)->toDateString(),
                'month' => $cursor->format('Y-m'),
            };
            if ($buckets === [] || $buckets[array_key_last($buckets)]['key'] !== $key) {
                $buckets[] = ['key' => $key, 'from' => $day, 'to' => $day, 'days' => []];
            }
            $last = array_key_last($buckets);
            $buckets[$last]['to'] = $day;
            $buckets[$last]['days'][] = $day;
            $cursor = $cursor->addDay();
        }

        return $buckets;
    }
}
