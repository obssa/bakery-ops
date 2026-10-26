<?php

declare(strict_types=1);

use App\Modules\Analytics\Read\Buckets;
use App\Modules\Analytics\Read\Window;
use App\Shared\Time\LocalDay;

/*
 * The range arithmetic of the dashboards (step 4.4c), pure: Window is the range asked for and, with ?compare=previous, the same number of
 * local days just before it; Buckets cuts a range into the points of a series, a day, a Monday-to-Sunday week (keyed by its Monday, cut
 * at the range's ends) or a calendar month (keyed YYYY-MM).
 */

const WINDOW_TZ = 'Africa/Addis_Ababa';

function windowRange(string $from, string $to): LocalDay
{
    return LocalDay::between($from, $to, WINDOW_TZ);
}

it('compares a range with the same number of days just before it', function (string $from, string $to, string $pFrom, string $pTo) {
    $window = Window::of(windowRange($from, $to), true);

    expect($window->previous?->from)->toBe($pFrom)
        ->and($window->previous?->to)->toBe($pTo)
        ->and($window->first())->toBe($pFrom)
        ->and($window->last())->toBe($to)
        ->and(count($window->periods()))->toBe(2);
})->with([
    'a week' => ['2026-11-16', '2026-11-22', '2026-11-09', '2026-11-15'],
    'one day' => ['2026-11-22', '2026-11-22', '2026-11-21', '2026-11-21'],
    'March, over a short February' => ['2026-03-01', '2026-03-31', '2026-01-29', '2026-02-28'],
])->group('unit', 'module:analytics');

it('has no period before when no comparison is asked for', function () {
    $window = Window::of(windowRange('2026-11-16', '2026-11-22'), false);

    expect($window->previous)->toBeNull()
        ->and($window->first())->toBe('2026-11-16')
        ->and($window->periods())->toHaveCount(1);
})->group('unit', 'module:analytics');

it('cuts a range into Monday-to-Sunday weeks keyed by the Monday, the first and last cut at the range\'s ends', function () {
    $weeks = Buckets::of(windowRange('2026-11-01', '2026-11-22'), 'week');

    expect(array_map(fn (array $b): array => [$b['key'], $b['from'], $b['to'], count($b['days'])], $weeks))->toBe([
        ['2026-10-26', '2026-11-01', '2026-11-01', 1],
        ['2026-11-02', '2026-11-02', '2026-11-08', 7],
        ['2026-11-09', '2026-11-09', '2026-11-15', 7],
        ['2026-11-16', '2026-11-16', '2026-11-22', 7],
    ])->and(Buckets::of(windowRange('2026-12-28', '2027-01-03'), 'week'))->toHaveCount(1)
        ->and(Buckets::of(windowRange('2026-11-17', '2026-11-18'), 'week')[0]['key'])->toBe('2026-11-16');
})->group('unit', 'module:analytics');

it('cuts a range into calendar months keyed YYYY-MM, and into days', function () {
    $months = Buckets::of(windowRange('2026-10-20', '2026-12-03'), 'month');
    $days = Buckets::of(windowRange('2026-11-30', '2026-12-01'), 'day');

    expect(array_map(fn (array $b): array => [$b['key'], $b['from'], $b['to'], count($b['days'])], $months))->toBe([
        ['2026-10', '2026-10-20', '2026-10-31', 12],
        ['2026-11', '2026-11-01', '2026-11-30', 30],
        ['2026-12', '2026-12-01', '2026-12-03', 3],
    ])->and($days)->toBe([
        ['key' => '2026-11-30', 'from' => '2026-11-30', 'to' => '2026-11-30', 'days' => ['2026-11-30']],
        ['key' => '2026-12-01', 'from' => '2026-12-01', 'to' => '2026-12-01', 'days' => ['2026-12-01']],
    ]);
})->group('unit', 'module:analytics');
