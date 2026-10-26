<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonInterface;

/**
 * A date of the Ethiopian calendar (step 4.3e: the receipt prints it when receipt_settings.ethiopian_dates is on): twelve months of 30
 * days and Pagume of 5 days, 6 in a year whose number plus one is divisible by 4, the new year on Meskerem 1 (September 11, or 12 in the
 * Gregorian year before a leap year). A label only, in Western digits: nothing stored is ever in this calendar.
 *
 * of() takes the calendar day of the instant as given (pass a local time: the branch's day), goes to its Julian day number and from there
 * to the Ethiopian (Amete Mihret) date, with integer arithmetic only, so the PHP calendar extension is not needed on the host.
 */
final readonly class EthiopianDate
{
    /** The Julian day number of Meskerem 1 of year 1 (Amete Mihret) minus 365: the epoch the standard conversion counts from. */
    private const int EPOCH = 1723856;

    /** @var array<int, string> */
    public const array MONTHS_EN = [
        1 => 'Meskerem', 2 => 'Tikimt', 3 => 'Hidar', 4 => 'Tahsas', 5 => 'Tir', 6 => 'Yekatit', 7 => 'Megabit',
        8 => 'Miyazya', 9 => 'Ginbot', 10 => 'Sene', 11 => 'Hamle', 12 => 'Nehase', 13 => 'Pagume',
    ];

    /** @var array<int, string> */
    public const array MONTHS_AM = [
        1 => 'መስከረም', 2 => 'ጥቅምት', 3 => 'ኅዳር', 4 => 'ታኅሣሥ', 5 => 'ጥር', 6 => 'የካቲት', 7 => 'መጋቢት',
        8 => 'ሚያዝያ', 9 => 'ግንቦት', 10 => 'ሰኔ', 11 => 'ሐምሌ', 12 => 'ነሐሴ', 13 => 'ጳጉሜ',
    ];

    private function __construct(
        public int $year,
        public int $month,
        public int $day,
    ) {}

    public static function of(CarbonInterface $date): self
    {
        $jdn = self::julianDay((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));
        $days = $jdn - self::EPOCH;
        $r = self::mod($days, 1461);
        $n = self::mod($r, 365) + 365 * intdiv($r, 1460);
        $year = 4 * self::floorDiv($days, 1461) + intdiv($r, 365) - intdiv($r, 1460);

        return new self($year, intdiv($n, 30) + 1, self::mod($n, 30) + 1);
    }

    /** "Tikimt 16, 2019" or "ጥቅምት 16 ቀን 2019 ዓ.ም." (am); any other locale reads in English. */
    public function format(string $locale): string
    {
        return $locale === 'am'
            ? sprintf('%s %d ቀን %d ዓ.ም.', self::MONTHS_AM[$this->month], $this->day, $this->year)
            : sprintf('%s %d, %d', self::MONTHS_EN[$this->month], $this->day, $this->year);
    }

    /** The Julian day number of a day of the proleptic Gregorian calendar. */
    private static function julianDay(int $year, int $month, int $day): int
    {
        $a = intdiv(14 - $month, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;

        return $day + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) - 32045;
    }

    private static function mod(int $a, int $b): int
    {
        return (($a % $b) + $b) % $b;
    }

    private static function floorDiv(int $a, int $b): int
    {
        return intdiv($a - self::mod($a, $b), $b);
    }
}
