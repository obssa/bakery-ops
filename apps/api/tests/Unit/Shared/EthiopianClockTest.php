<?php

declare(strict_types=1);

use App\Shared\Time\EthiopianClock;
use Carbon\CarbonImmutable;

/*
 * The Ethiopian clock (Phase 6 follow-up, Ramin 2026-11-10), the same rule as the web's lib/clock.ts (e2e/p6/clock-logic.spec.ts): the
 * day's hours start at 06:00, which is 12 in the morning; 07:00 is 1. Four parts: morning (ጠዋት) 06:00-11:59, afternoon (ከሰዓት)
 * 12:00-17:59, evening (ማታ) 18:00-23:59, night (ሌሊት) 00:00-05:59. The international clock goes in brackets when the admin asks for it.
 */

uses()->group('unit', 'module:shared');

it('reads the boundaries of every part of the day', function (string $time, int $hour, string $part) {
    [$h, $m] = array_map('intval', explode(':', $time));
    $clock = EthiopianClock::at($h, $m);

    expect([$clock->hour, $clock->minute, $clock->part])->toBe([$hour, $m, $part]);
})->with([
    ['00:00', 6, 'night'],
    ['00:15', 6, 'night'],
    ['05:59', 11, 'night'],
    ['06:00', 12, 'morning'],
    ['07:00', 1, 'morning'],
    ['07:50', 1, 'morning'],
    ['11:59', 5, 'morning'],
    ['12:00', 6, 'afternoon'],
    ['16:10', 10, 'afternoon'],
    ['17:59', 11, 'afternoon'],
    ['18:00', 12, 'evening'],
    ['19:00', 1, 'evening'],
    ['23:05', 5, 'evening'],
    ['23:59', 5, 'evening'],
]);

it('gives every minute of the day an hour of 1 to 12 and turns back into the same 24-hour time', function () {
    // morning and afternoon are the day's twelve hours, evening and night the night's
    $offsets = ['morning' => 0, 'afternoon' => 0, 'evening' => 12, 'night' => 12];
    for ($h = 0; $h < 24; $h++) {
        for ($m = 0; $m < 60; $m++) {
            $clock = EthiopianClock::at($h, $m);
            expect($clock->hour)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(12);
            // the web's hour24Of: ((hour mod 12) + 6 + 12 for the night's half) mod 24
            expect((($clock->hour % 12) + 6 + $offsets[$clock->part]) % 24)->toBe($h);
        }
    }
});

it('writes the time in English, Amharic or both, with the international clock when asked', function () {
    $afternoon = EthiopianClock::at(16, 10);

    expect($afternoon->format('en'))->toBe('10:10 afternoon')
        ->and($afternoon->format('am'))->toBe('10:10 ከሰዓት')
        ->and($afternoon->format('both'))->toBe('10:10 afternoon / ከሰዓት')
        ->and($afternoon->format('en', 'h12'))->toBe('10:10 afternoon (4:10 PM)')
        ->and($afternoon->format('am', 'h24'))->toBe('10:10 ከሰዓት (16:10)')
        ->and($afternoon->format('en', 'none'))->toBe('10:10 afternoon')
        ->and(EthiopianClock::at(0, 15)->format('am', 'h12'))->toBe('6:15 ሌሊት (12:15 AM)')
        ->and(EthiopianClock::at(12, 0)->format('en', 'h12'))->toBe('6:00 afternoon (12:00 PM)')
        ->and(EthiopianClock::at(7, 5)->format('en', 'h24'))->toBe('1:05 morning (07:05)');
});

it('reads the wall clock of a local instant and stamps it with its date', function () {
    $local = CarbonImmutable::parse('2026-10-26 09:15:00', 'Africa/Addis_Ababa');

    expect(EthiopianClock::of($local)->format('en'))->toBe('3:15 morning')
        ->and(EthiopianClock::stamp($local, 'am'))->toBe('2026-10-26 3:15 ጠዋት')
        ->and(EthiopianClock::stamp(CarbonImmutable::parse('2026-10-26 06:30:00', 'UTC')->setTimezone('Africa/Addis_Ababa'), 'en', 'h24'))
        ->toBe('2026-10-26 3:30 morning (09:30)');
});

it('refuses a time that is not a time of day', function (int $h, int $m) {
    EthiopianClock::at($h, $m);
})->with([[24, 0], [-1, 0], [12, 60]])->throws(InvalidArgumentException::class);
