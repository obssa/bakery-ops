<?php

declare(strict_types=1);

use App\Modules\Shifts\Support\LocalClock;
use App\Modules\Shifts\Support\ShiftWindow;
use App\Modules\Shifts\Support\TemplateWindow;
use Carbon\CarbonImmutable;

/*
 * Phase 3 plan section 10.2 (step 3.2a): which window of which template contains an instant, and the local start date of
 * that window in Africa/Addis_Ababa. 2026-10-23 is a Friday, 2026-10-24 a Saturday, 2026-10-25 a Sunday. Every instant here
 * is written as local wall-clock time; the shift's starts_at / ends_at are UTC (Addis Ababa is UTC+3, no daylight saving).
 */

/** Local wall-clock time in the bakery's timezone. */
function localAt(string $dateTime): CarbonImmutable
{
    return CarbonImmutable::parse($dateTime, 'Africa/Addis_Ababa');
}

function nightTemplate(int $id = 1, int $mask = 127): TemplateWindow
{
    return new TemplateWindow($id, '22:00', '06:00', $mask);
}

function dayTemplate(int $id = 2, int $mask = 127): TemplateWindow
{
    return new TemplateWindow($id, '06:00', '14:00', $mask);
}

/** @return array{0: string, 1: array<int, TemplateWindow>, 2: string|null} the section 10.2 table: instant, templates, the date or null for no match */
dataset('section 10.2 table', [
    'Night, Fri 21:59: no match' => ['2026-10-23 21:59', fn () => [nightTemplate()], null],
    'Night, Fri 22:00: match, date Fri' => ['2026-10-23 22:00', fn () => [nightTemplate()], '2026-10-23'],
    'Night, Fri 23:59: match, date Fri' => ['2026-10-23 23:59', fn () => [nightTemplate()], '2026-10-23'],
    'Night, Sat 00:00: match, date Fri' => ['2026-10-24 00:00', fn () => [nightTemplate()], '2026-10-23'],
    'Night, Sat 00:01: match, date Fri' => ['2026-10-24 00:01', fn () => [nightTemplate()], '2026-10-23'],
    'Night, Sat 05:59: match, date Fri' => ['2026-10-24 05:59', fn () => [nightTemplate()], '2026-10-23'],
    'Night, Sat 06:00: end exclusive, no match' => ['2026-10-24 06:00', fn () => [nightTemplate()], null],
    'Night on Fridays only, Sat 01:00: the weekday of the start counts' => ['2026-10-24 01:00', fn () => [nightTemplate(1, TemplateWindow::maskOf([5]))], '2026-10-23'],
    'Night on Fridays only, Sat 23:00: no match' => ['2026-10-24 23:00', fn () => [nightTemplate(1, TemplateWindow::maskOf([5]))], null],
    'Night on Fridays only, Sun 01:00: no match' => ['2026-10-25 01:00', fn () => [nightTemplate(1, TemplateWindow::maskOf([5]))], null],
    'Night on Fridays only, Fri 22:30: match' => ['2026-10-23 22:30', fn () => [nightTemplate(1, TemplateWindow::maskOf([5]))], '2026-10-23'],
    'Day 06:00-14:00, 13:59: match' => ['2026-10-23 13:59', fn () => [dayTemplate()], '2026-10-23'],
    'Day 06:00-14:00, 14:00: no match' => ['2026-10-23 14:00', fn () => [dayTemplate()], null],
    'Day 06:00-14:00, 06:00: match (start inclusive)' => ['2026-10-23 06:00', fn () => [dayTemplate()], '2026-10-23'],
    'Day and Night together, Sat 05:59: the Night of Friday' => ['2026-10-24 05:59', fn () => [dayTemplate(), nightTemplate()], '2026-10-23'],
    'Day and Night together, Sat 06:00: the Day of Saturday' => ['2026-10-24 06:00', fn () => [dayTemplate(), nightTemplate()], '2026-10-24'],
    'a window ending exactly at midnight, 23:59' => ['2026-10-23 23:59', fn () => [new TemplateWindow(3, '18:00', '00:00', 127)], '2026-10-23'],
    'a window ending exactly at midnight, 00:00: no match' => ['2026-10-24 00:00', fn () => [new TemplateWindow(3, '18:00', '00:00', 127)], null],
    'a window starting exactly at midnight, 00:00' => ['2026-10-24 00:00', fn () => [new TemplateWindow(4, '00:00', '04:00', 127)], '2026-10-24'],
]);

it('answers the section 10.2 table', function (string $instant, Closure $templates, ?string $date) {
    $window = ShiftWindow::containing(localAt($instant), $templates());

    expect($window?->date)->toBe($date);
})->with('section 10.2 table')->group('unit', 'module:shifts', 'gate:p3');

it('gives starts_at and ends_at as the window in UTC, the Night of Friday ending on Saturday morning', function () {
    $window = ShiftWindow::containing(localAt('2026-10-24 00:01'), [nightTemplate(7)]);

    expect($window)->not->toBeNull()
        ->and($window->templateId)->toBe(7)
        ->and($window->date)->toBe('2026-10-23')
        ->and($window->startsAt->format('Y-m-d H:i:s'))->toBe('2026-10-23 19:00:00')
        ->and($window->endsAt->format('Y-m-d H:i:s'))->toBe('2026-10-24 03:00:00')
        ->and($window->startsAt->getTimezone()->getName())->toBe('UTC')
        ->and($window->endsAt->getTimezone()->getName())->toBe('UTC');
});

it('takes the local date from the local time, never the UTC date, at 23:30 and 01:30 local', function () {
    // 23:30 local Friday is 20:30 UTC Friday; 01:30 local Saturday is 22:30 UTC Friday: one Night, one date, from local time
    $late = ShiftWindow::containing(CarbonImmutable::parse('2026-10-23 20:30:00', 'UTC'), [nightTemplate()]);
    $early = ShiftWindow::containing(CarbonImmutable::parse('2026-10-23 22:30:00', 'UTC'), [nightTemplate()]);

    expect($late?->date)->toBe('2026-10-23')
        ->and($early?->date)->toBe('2026-10-23')
        ->and($early?->startsAt->equalTo($late?->startsAt))->toBeTrue();
});

it('lets the later start win when two templates match, and the lower template id on a tie', function () {
    $day = dayTemplate(2);
    $custom = new TemplateWindow(9, '12:00', '18:00', 127);
    $twin = new TemplateWindow(5, '12:00', '18:00', 127);

    expect(ShiftWindow::containing(localAt('2026-10-23 13:00'), [$day, $custom])?->templateId)->toBe(9)
        ->and(ShiftWindow::containing(localAt('2026-10-23 13:00'), [$custom, $day])?->templateId)->toBe(9)
        ->and(ShiftWindow::containing(localAt('2026-10-23 13:00'), [$custom, $twin])?->templateId)->toBe(5)
        ->and(ShiftWindow::containing(localAt('2026-10-23 13:00'), [$twin, $custom])?->templateId)->toBe(5)
        ->and(array_map(fn (ShiftWindow $w): int => $w->templateId, ShiftWindow::matching(localAt('2026-10-23 13:00'), [$day, $custom, $twin])))->toBe([5, 9, 2]);
});

it('lets a Night that started yesterday lose to a Day that started today, and win while nothing newer matches', function () {
    $longNight = new TemplateWindow(1, '20:00', '10:00', 127);

    // Sat 07:00: the Friday 20:00 night is still running and so is the Saturday 06:00 day: the later start (the day) wins
    expect(ShiftWindow::containing(localAt('2026-10-24 07:00'), [$longNight, dayTemplate()])?->templateId)->toBe(2)
        ->and(ShiftWindow::containing(localAt('2026-10-24 07:00'), [$longNight, dayTemplate()])?->date)->toBe('2026-10-24')
        // Sat 05:00: only the Friday night matches
        ->and(ShiftWindow::containing(localAt('2026-10-24 05:00'), [$longNight, dayTemplate()])?->date)->toBe('2026-10-23');
});

it('gives an ad-hoc shift the local date of the instant when no template matches', function () {
    expect(ShiftWindow::containing(localAt('2026-10-23 21:59'), [nightTemplate()]))->toBeNull()
        ->and(ShiftWindow::adHocDate(localAt('2026-10-23 21:59')))->toBe('2026-10-23')
        ->and(ShiftWindow::adHocDate(localAt('2026-10-24 06:00')))->toBe('2026-10-24')
        // 22:30 UTC on Friday is 01:30 local on Saturday
        ->and(ShiftWindow::adHocDate(CarbonImmutable::parse('2026-10-23 22:30:00', 'UTC')))->toBe('2026-10-24')
        ->and(ShiftWindow::containing(localAt('2026-10-23 12:00'), []))->toBeNull();
});

it('builds the window of one template for a given local date', function () {
    $window = ShiftWindow::forDate(nightTemplate(3), '2026-10-23');

    expect($window->date)->toBe('2026-10-23')
        ->and($window->startsAt->format('Y-m-d H:i:s'))->toBe('2026-10-23 19:00:00')
        ->and($window->endsAt->format('Y-m-d H:i:s'))->toBe('2026-10-24 03:00:00')
        ->and(ShiftWindow::forDate(dayTemplate(), '2026-10-23')->endsAt->format('Y-m-d H:i:s'))->toBe('2026-10-23 11:00:00');
});

it('refuses a template whose start equals its end, a mask outside 1 to 127 and a malformed time', function () {
    expect(fn () => new TemplateWindow(1, '08:00', '08:00', 127))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TemplateWindow(1, '00:00', '00:00', 127))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TemplateWindow(1, '08:00', '09:00', 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TemplateWindow(1, '08:00', '09:00', 128))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TemplateWindow(1, '25:00', '09:00', 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TemplateWindow(1, '8am', '09:00', 1))->toThrow(InvalidArgumentException::class);
});

it('reads times as the database gives them, with or without seconds', function () {
    $window = new TemplateWindow(1, '22:00:00', '06:00:00', 127);

    expect($window->startTime)->toBe('22:00')
        ->and($window->endTime)->toBe('06:00')
        ->and($window->crossesMidnight())->toBeTrue()
        ->and(dayTemplate()->crossesMidnight())->toBeFalse();
});

it('maps ISO weekdays to the days mask, Monday as bit 0', function () {
    expect(TemplateWindow::maskOf([1]))->toBe(1)
        ->and(TemplateWindow::maskOf([7]))->toBe(64)
        ->and(TemplateWindow::maskOf([1, 2, 3, 4, 5, 6, 7]))->toBe(127)
        ->and(TemplateWindow::maskOf([5, 5, 1]))->toBe(17)
        ->and(nightTemplate(1, 16)->daysOfWeek())->toBe([5])
        ->and(nightTemplate()->runsOn(7))->toBeTrue()
        ->and(fn () => TemplateWindow::maskOf([0]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TemplateWindow::maskOf([8]))->toThrow(InvalidArgumentException::class);
});

it('knows the local clock: local date, weekday and wall-clock to UTC', function () {
    $clock = new LocalClock;

    expect($clock->timezone())->toBe('Africa/Addis_Ababa')
        ->and($clock->localDate(CarbonImmutable::parse('2026-10-23 22:30:00', 'UTC')))->toBe('2026-10-24')
        ->and($clock->localDate(CarbonImmutable::parse('2026-10-23 20:30:00', 'UTC')))->toBe('2026-10-23')
        ->and($clock->isoWeekday('2026-10-23'))->toBe(5)
        ->and($clock->isoWeekday('2026-10-25'))->toBe(7)
        ->and($clock->toUtc('2026-10-23', '22:00')->format('Y-m-d H:i:s'))->toBe('2026-10-23 19:00:00')
        ->and($clock->toUtc('2026-10-24', '00:00')->format('Y-m-d H:i:s'))->toBe('2026-10-23 21:00:00')
        ->and($clock->toLocal(CarbonImmutable::parse('2026-10-23 21:00:00', 'UTC'))->format('Y-m-d H:i'))->toBe('2026-10-24 00:00')
        ->and((new LocalClock('UTC'))->localDate(CarbonImmutable::parse('2026-10-23 22:30:00', 'UTC')))->toBe('2026-10-23');
});

it('finds the same window whatever zone the instant carries, over a week of minutes', function () {
    $templates = [nightTemplate(1), dayTemplate(2), new TemplateWindow(3, '12:00', '18:00', TemplateWindow::maskOf([6, 7]))];
    $start = localAt('2026-10-19 00:00');

    for ($minute = 0; $minute < 7 * 24 * 60; $minute += 7) {
        $local = $start->addMinutes($minute);
        $utc = CarbonImmutable::parse($local->utc()->format('Y-m-d H:i:s'), 'UTC');
        $a = ShiftWindow::containing($local, $templates);
        $b = ShiftWindow::containing($utc, $templates);

        expect($a?->templateId)->toBe($b?->templateId)->and($a?->date)->toBe($b?->date);
        if ($a !== null) {
            expect($a->startsAt <= $utc && $utc < $a->endsAt)->toBeTrue("{$local->format('c')} inside its own window");
        }
    }
});
