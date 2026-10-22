<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The branch's wall clock (phase-3 plan section 10.2). Times are stored in UTC and bucketed by the branch's timezone
 * (Africa/Addis_Ababa today); a shift's `date` is the local date its window starts. Pure: it holds a timezone name and
 * nothing else, reads no database and no clock. There is no `+3` anywhere: every conversion goes through the zone, so a
 * branch in another zone, or one that observes daylight saving, needs no other change.
 */
final readonly class LocalClock
{
    public const string DEFAULT_TIMEZONE = 'Africa/Addis_Ababa';

    public function __construct(private string $timezone = self::DEFAULT_TIMEZONE) {}

    public function timezone(): string
    {
        return $this->timezone;
    }

    /** The instant on this clock's wall. */
    public function toLocal(CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone($this->timezone);
    }

    /** The local calendar date (Y-m-d) of an instant. */
    public function localDate(CarbonInterface $instant): string
    {
        return $this->toLocal($instant)->toDateString();
    }

    /** The ISO weekday (1 Monday .. 7 Sunday) of a local date (Y-m-d). */
    public function isoWeekday(string $date): int
    {
        return $this->parseDate($date)->dayOfWeekIso;
    }

    /** The UTC instant of a local date (Y-m-d) at a local wall-clock time (HH:MM). */
    public function toUtc(string $date, string $time): CarbonImmutable
    {
        return $this->parse('!Y-m-d H:i', $date.' '.$time)->utc();
    }

    /** The local date (Y-m-d) the given number of days after (before, when negative) a local date. */
    public function addDays(string $date, int $days): string
    {
        return $this->parseDate($date)->addDays($days)->toDateString();
    }

    private function parseDate(string $date): CarbonImmutable
    {
        return $this->parse('!Y-m-d', $date);
    }

    private function parse(string $format, string $value): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat($format, $value, $this->timezone);
        if (! $parsed instanceof CarbonImmutable) {
            throw new InvalidArgumentException("\"{$value}\" does not match the format {$format}.");
        }

        return $parsed;
    }
}
