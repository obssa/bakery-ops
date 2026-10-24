<?php

declare(strict_types=1);

namespace App\Shared\Time;

use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * An inclusive range of LOCAL calendar dates (phase-4 plan section 10.5; the read side of every Facts port). Times are stored in
 * UTC and a day is bucketed by the branch's timezone, Africa/Addis_Ababa by default (UTC+3, no daylight saving; the code still
 * asks the zone, it does not assume the offset). `from` and `to` are 'YYYY-MM-DD' strings and both are part of the range.
 *
 * - between(from, to, tz?): the range; a timezone given here wins, else the current branch's, else config('bakery.timezone').
 * - utcBounds(tz?): [start, end) as UTC instants: the start of the first local day and the start of the day after the last, so a row
 *   with start <= clock < end belongs to the range and a row at 23:59:59 local is in, 00:00:00 of the next day is out.
 * - dayOf(instant, tz?): the local date of an instant.
 * - days(tz?): every local date of the range, ascending.
 */
final readonly class LocalDay
{
    private const string DEFAULT_ZONE = 'Africa/Addis_Ababa';

    private function __construct(
        public string $from,
        public string $to,
        private ?string $timezone,
    ) {}

    public static function between(string $from, string $to, ?string $timezone = null): self
    {
        self::assertDate($from);
        self::assertDate($to);
        if ($from > $to) {
            throw new InvalidArgumentException("LocalDay: from {$from} is after to {$to}");
        }

        return new self($from, $to, $timezone);
    }

    public static function of(string $day, ?string $timezone = null): self
    {
        return self::between($day, $day, $timezone);
    }

    /** The zone this range is read in: the one given, else the current branch's, else the configured default. */
    public function timezone(): string
    {
        if ($this->timezone !== null && $this->timezone !== '') {
            return $this->timezone;
        }
        $branch = app(CurrentBranch::class);
        if ($branch->isResolved()) {
            return $branch->timezone();
        }
        $configured = config('bakery.timezone');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_ZONE;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [start, end) in UTC
     */
    public function utcBounds(?string $timezone = null): array
    {
        $zone = $timezone ?? $this->timezone();
        $start = CarbonImmutable::parse($this->from.' 00:00:00', $zone);
        $end = CarbonImmutable::parse($this->to.' 00:00:00', $zone);

        return [$start->utc(), $end->addDay()->startOfDay()->utc()];
    }

    /** The local date ('YYYY-MM-DD') of an instant; a string is read as UTC. */
    public function dayOf(DateTimeInterface|string $instant, ?string $timezone = null): string
    {
        $at = $instant instanceof DateTimeInterface
            ? CarbonImmutable::instance($instant)
            : CarbonImmutable::parse($instant, 'UTC');

        return $at->setTimezone($timezone ?? $this->timezone())->toDateString();
    }

    /** The local hour (0-23) of an instant. */
    public function hourOf(DateTimeInterface|string $instant, ?string $timezone = null): int
    {
        $at = $instant instanceof DateTimeInterface
            ? CarbonImmutable::instance($instant)
            : CarbonImmutable::parse($instant, 'UTC');

        return (int) $at->setTimezone($timezone ?? $this->timezone())->format('G');
    }

    /**
     * Every local date of the range, ascending.
     *
     * @return list<string>
     */
    public function days(): array
    {
        $zone = $this->timezone();
        $cursor = CarbonImmutable::parse($this->from.' 00:00:00', $zone);
        $days = [];
        while ($cursor->toDateString() <= $this->to) {
            $days[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    private static function assertDate(string $value): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || CarbonImmutable::parse($value.' 00:00:00', 'UTC')->toDateString() !== $value) {
            throw new InvalidArgumentException("LocalDay: '{$value}' is not a YYYY-MM-DD date");
        }
    }
}
