<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use InvalidArgumentException;

/**
 * The part of a shift template that decides when its windows fall (shift_templates.start_time, end_time, days_mask), as a
 * plain value so ShiftWindow stays pure and testable without the database. Times are the branch's local wall-clock times
 * (HH:MM, or HH:MM:SS as the database gives them); end_time before start_time is the cross-midnight window (a Night 22:00 to
 * 06:00), equal times are refused (a template is never exactly 24 hours); days_mask bit 0 is Monday .. bit 6 Sunday, the
 * ISO weekday of the day the window starts.
 */
final readonly class TemplateWindow
{
    public string $startTime;

    public string $endTime;

    public function __construct(public int $id, string $startTime, string $endTime, public int $daysMask)
    {
        $this->startTime = self::normalise($startTime);
        $this->endTime = self::normalise($endTime);

        if ($this->startTime === $this->endTime) {
            throw new InvalidArgumentException('A template window never starts and ends at the same time.');
        }
        if ($daysMask < 1 || $daysMask > 127) {
            throw new InvalidArgumentException('days_mask must be between 1 and 127.');
        }
    }

    /**
     * @param  list<int>  $isoWeekdays  1 (Monday) .. 7 (Sunday)
     */
    public static function maskOf(array $isoWeekdays): int
    {
        $mask = 0;
        foreach ($isoWeekdays as $day) {
            if ($day < 1 || $day > 7) {
                throw new InvalidArgumentException("{$day} is not an ISO weekday (1 to 7).");
            }
            $mask |= 1 << ($day - 1);
        }

        return $mask;
    }

    /** @return list<int> the ISO weekdays (1 to 7) the window may start on */
    public function daysOfWeek(): array
    {
        $days = [];
        for ($day = 1; $day <= 7; $day++) {
            if ($this->runsOn($day)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    public function runsOn(int $isoWeekday): bool
    {
        return ($this->daysMask & (1 << ($isoWeekday - 1))) !== 0;
    }

    /** Whether the window ends on the day after it starts (end earlier than start, midnight included). */
    public function crossesMidnight(): bool
    {
        return $this->endTime < $this->startTime;
    }

    private static function normalise(string $time): string
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $time, $m) !== 1) {
            throw new InvalidArgumentException("\"{$time}\" is not a time of day (HH:MM).");
        }

        return $m[1].':'.$m[2];
    }
}
