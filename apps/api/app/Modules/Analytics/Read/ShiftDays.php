<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Read;

use App\Shared\Time\LocalDay;
use Brick\Math\BigDecimal;
use stdClass;

/**
 * The shifts of a period a day at a time, as GET /analytics/shifts gives them (step 4.4c) and the shifts export prints them (step 4.4d),
 * from daily_station_metrics summed over the stations: the shifts that ran each day (a Night shift counts on both days), those of them
 * locked, and the variance lines and cash variance of the shifts dated that day. A built day with no station row is zeros, a day not built
 * null figures (R-U). The totals add up the variances, each of which belongs to one day (the shift's date); shift-days are not summed. Pure.
 */
final class ShiftDays
{
    /**
     * @param  array<string, stdClass>  $rows  Summaries::shiftDays
     * @return array{days: list<array{day: string, built: bool, shifts_count: int|null, shifts_locked_count: int|null, variance_line_count: int|null, cash_variance_total: string|null}>, totals: array{variance_line_count: int, cash_variance_total: string}}
     */
    public static function of(LocalDay $period, BuiltDays $built, array $rows): array
    {
        $days = [];
        $lines = 0;
        $cash = BigDecimal::zero();
        foreach ($period->days() as $day) {
            if (! $built->has($day)) {
                $days[] = ['day' => $day, 'built' => false, 'shifts_count' => null, 'shifts_locked_count' => null, 'variance_line_count' => null, 'cash_variance_total' => null];

                continue;
            }
            $row = $rows[$day] ?? null;
            $lines += Sum::int($row?->variance_line_count);
            $cash = $cash->plus(Sum::money($row?->cash_variance_total));
            $days[] = [
                'day' => $day,
                'built' => true,
                'shifts_count' => Sum::int($row?->shifts_count),
                'shifts_locked_count' => Sum::int($row?->shifts_locked_count),
                'variance_line_count' => Sum::int($row?->variance_line_count),
                'cash_variance_total' => Sum::money($row?->cash_variance_total),
            ];
        }

        return ['days' => $days, 'totals' => ['variance_line_count' => $lines, 'cash_variance_total' => Sum::money($cash)]];
    }
}
