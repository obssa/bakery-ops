<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use App\Shared\Time\LocalDay;
use Carbon\CarbonImmutable;

/*
 * LocalDay (phase-4 plan section 10.5): an inclusive range of local dates read in the branch's zone. Africa/Addis_Ababa is UTC+3
 * all year, so 23:59:59 local is 20:59:59Z and 00:00:00 local the next day is 21:00:00Z.
 */

it('bounds a range as [start of the first local day, start of the day after the last) in UTC', function () {
    [$start, $end] = LocalDay::between('2026-11-20', '2026-11-21')->utcBounds();

    expect($start->format('Y-m-d H:i:s'))->toBe('2026-11-19 21:00:00')
        ->and($end->format('Y-m-d H:i:s'))->toBe('2026-11-21 21:00:00')
        ->and($start->timezoneName)->toBe('UTC');
});

it('puts 23:59 and 00:01 local on the two sides of midnight', function () {
    $range = LocalDay::between('2026-11-20', '2026-11-21');

    expect($range->dayOf(CarbonImmutable::parse('2026-11-20 20:59:59', 'UTC')))->toBe('2026-11-20')
        ->and($range->dayOf(CarbonImmutable::parse('2026-11-20 21:00:00', 'UTC')))->toBe('2026-11-21')
        ->and($range->dayOf('2026-11-20 21:01:00'))->toBe('2026-11-21')
        ->and($range->hourOf('2026-11-20 20:59:59'))->toBe(23)
        ->and($range->hourOf('2026-11-20 21:00:00'))->toBe(0);
});

it('gives a Night shift that starts at 22:00 local the date of its start, and 06:00 the next morning the next date', function () {
    $range = LocalDay::between('2026-11-20', '2026-11-21');

    expect($range->dayOf('2026-11-20 19:00:00'))->toBe('2026-11-20')   // 22:00 local, the shift's date
        ->and($range->dayOf('2026-11-21 03:00:00'))->toBe('2026-11-21'); // 06:00 local the next day
});

it('lists every date of the range, both ends included, and rejects bad input', function () {
    expect(LocalDay::between('2026-11-29', '2026-12-02')->days())->toBe(['2026-11-29', '2026-11-30', '2026-12-01', '2026-12-02'])
        ->and(LocalDay::of('2026-11-20')->days())->toBe(['2026-11-20'])
        ->and(fn () => LocalDay::between('2026-11-21', '2026-11-20'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => LocalDay::between('2026-02-30', '2026-03-01'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => LocalDay::between('20-11-2026', '2026-11-21'))->toThrow(InvalidArgumentException::class);
});

it('reads the zone given, else the current branch, else the configured default', function () {
    expect(LocalDay::of('2026-11-20')->timezone())->toBe('Africa/Addis_Ababa');

    $branch = actInBranch(Branch::factory()->create(['timezone' => 'Europe/Paris']));
    expect($branch->timezone)->toBe('Europe/Paris')
        ->and(LocalDay::of('2026-06-20')->timezone())->toBe('Europe/Paris')
        ->and(LocalDay::of('2026-06-20', 'Africa/Addis_Ababa')->timezone())->toBe('Africa/Addis_Ababa')
        ->and(LocalDay::of('2026-06-20')->utcBounds()[0]->format('Y-m-d H:i:s'))->toBe('2026-06-19 22:00:00')
        ->and(LocalDay::of('2026-06-20')->utcBounds('Africa/Addis_Ababa')[0]->format('Y-m-d H:i:s'))->toBe('2026-06-19 21:00:00');
});
