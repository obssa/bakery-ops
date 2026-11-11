<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A time of day as people in Ethiopia read it (Phase 6 follow-up, Ramin 2026-11-10), the same rule as the web's lib/clock.ts: the day's
 * twelve hours start at sunrise, so 06:00 is 12 o'clock in the morning and 07:00 is 1; 18:00 is 12 again, and the night's hours run 1 to 12
 * until the next morning. The hour is ((h + 6) mod 12), 12 for 0; the part of the day names which twelve:
 *
 *   morning   ጠዋት   06:00-11:59  (12:00 to 5:59)
 *   afternoon ከሰዓት  12:00-17:59  (6:00 to 11:59)
 *   evening   ማታ    18:00-23:59  (12:00 to 5:59)
 *   night     ሌሊት   00:00-05:59  (6:00 to 11:59)
 *
 * of() reads the wall clock of the instant as given (pass a local time: the branch's zone). A label only: every time stored and every
 * machine-readable export stays the 24-hour clock. format() can add the international clock after it, in brackets, when the admin turned
 * it on (brand_profiles.clock_also: none, h12, h24): "10:10 afternoon (4:10 PM)", "10:10 ከሰዓት (16:10)".
 */
final readonly class EthiopianClock
{
    /** @var list<string> the admin's choices for the international clock beside it */
    public const array ALSO = ['none', 'h12', 'h24'];

    /** @var array<string, string> */
    public const array PARTS_EN = ['morning' => 'morning', 'afternoon' => 'afternoon', 'evening' => 'evening', 'night' => 'night'];

    /** @var array<string, string> */
    public const array PARTS_AM = ['morning' => 'ጠዋት', 'afternoon' => 'ከሰዓት', 'evening' => 'ማታ', 'night' => 'ሌሊት'];

    private function __construct(
        /** 1 to 12 */
        public int $hour,
        public int $minute,
        /** morning, afternoon, evening or night */
        public string $part,
        /** the same time on the 24-hour clock, 0 to 23 */
        public int $hour24,
    ) {}

    public static function of(CarbonInterface $local): self
    {
        return self::at((int) $local->format('G'), (int) $local->format('i'));
    }

    public static function at(int $hour24, int $minute): self
    {
        if ($hour24 < 0 || $hour24 > 23 || $minute < 0 || $minute > 59) {
            throw new InvalidArgumentException("Not a time of day: {$hour24}:{$minute}.");
        }
        $part = match (true) {
            $hour24 < 6 => 'night',
            $hour24 < 12 => 'morning',
            $hour24 < 18 => 'afternoon',
            default => 'evening',
        };

        return new self((($hour24 + 6) % 12) ?: 12, $minute, $part, $hour24);
    }

    /** The part of the day in a language: en, am, or both ("morning / ጠዋት"). */
    public function partIn(string $locale): string
    {
        return match ($locale) {
            'am' => self::PARTS_AM[$this->part],
            'both' => self::PARTS_EN[$this->part].' / '.self::PARTS_AM[$this->part],
            default => self::PARTS_EN[$this->part],
        };
    }

    /** "1:50 morning", "1:50 ጠዋት", or "1:50 morning / ጠዋት"; with the international clock in brackets when $also asks for it. */
    public function format(string $locale, string $also = 'none'): string
    {
        $text = sprintf('%d:%02d %s', $this->hour, $this->minute, $this->partIn($locale));
        $international = $this->international($also);

        return $international === null ? $text : $text.' ('.$international.')';
    }

    /** "4:10 PM" (h12), "16:10" (h24), or null (none, or anything else). */
    public function international(string $also): ?string
    {
        return match ($also) {
            'h12' => sprintf('%d:%02d %s', ($this->hour24 % 12) ?: 12, $this->minute, $this->hour24 < 12 ? 'AM' : 'PM'),
            'h24' => sprintf('%02d:%02d', $this->hour24, $this->minute),
            default => null,
        };
    }

    /** A local instant as the printed pages write it: "2026-10-26 3:15 morning" (the date stays Y-m-d). */
    public static function stamp(CarbonInterface $local, string $locale, string $also = 'none'): string
    {
        return $local->format('Y-m-d').' '.self::of($local)->format($locale, $also);
    }
}
