<?php

declare(strict_types=1);

use App\Shared\Time\EthiopianDate;
use Carbon\CarbonImmutable;

/*
 * The Ethiopian calendar date of a local day (step 4.3e: the receipt prints it when receipt_settings.ethiopian_dates is on; step 4.5f
 * shows it in the web app). Twelve months of 30 days and Pagume of 5, or 6 in the year before a year divisible by 4 (2015 E.C. had a
 * Pagume 6), with the new year on Meskerem 1: September 11, or September 12 in the Gregorian year before a leap year. A label only,
 * printed in Western digits; nothing stored changes. Computed through the Julian day number, so no PHP calendar extension is needed.
 */

uses()->group('unit', 'module:shared');

it('converts a Gregorian day to the Ethiopian one', function (string $gregorian, int $year, int $month, int $day) {
    $date = EthiopianDate::of(CarbonImmutable::parse($gregorian.' 12:00:00', 'Africa/Addis_Ababa'));

    expect([$date->year, $date->month, $date->day])->toBe([$year, $month, $day]);
})->with([
    'new year 2016, the year after a leap year' => ['2023-09-12', 2016, 1, 1],
    'Pagume 6 of 2015' => ['2023-09-11', 2015, 13, 6],
    'Pagume 5 of 2016' => ['2024-09-10', 2016, 13, 5],
    'new year 2017' => ['2024-09-11', 2017, 1, 1],
    'Christmas 2016 on Tahsas 28' => ['2024-01-07', 2016, 4, 28],
    'Christmas 2017 on Tahsas 29' => ['2025-01-07', 2017, 4, 29],
    'new year 2018' => ['2025-09-11', 2018, 1, 1],
    'a day in Tikimt 2019' => ['2026-10-26', 2019, 2, 16],
    'the last day of Nehase 2018' => ['2026-09-05', 2018, 12, 30],
    'Pagume 1 of 2018' => ['2026-09-06', 2018, 13, 1],
    'Gregorian leap day' => ['2024-02-29', 2016, 6, 21],
]);

it('takes the local day of the instant it is given', function () {
    // 21:30 UTC on the 25th is 00:30 on the 26th in Addis Ababa
    $date = EthiopianDate::of(CarbonImmutable::parse('2026-10-25 21:30:00', 'UTC')->setTimezone('Africa/Addis_Ababa'));

    expect($date->day)->toBe(16);
});

it('reads in English and in Amharic, with Western digits', function () {
    $date = EthiopianDate::of(CarbonImmutable::parse('2026-10-26', 'Africa/Addis_Ababa'));

    expect($date->format('en'))->toBe('Tikimt 16, 2019')
        ->and($date->format('am'))->toBe('ጥቅምት 16 ቀን 2019 ዓ.ም.')
        ->and(EthiopianDate::of(CarbonImmutable::parse('2023-09-11'))->format('en'))->toBe('Pagume 6, 2015')
        ->and(EthiopianDate::of(CarbonImmutable::parse('2023-09-11'))->format('am'))->toBe('ጳጉሜ 6 ቀን 2015 ዓ.ም.');
});

it('names thirteen months in both languages', function () {
    expect(EthiopianDate::MONTHS_EN)->toHaveCount(13)->and(EthiopianDate::MONTHS_AM)->toHaveCount(13)
        ->and(EthiopianDate::MONTHS_EN[1])->toBe('Meskerem')->and(EthiopianDate::MONTHS_AM[1])->toBe('መስከረም');
});
