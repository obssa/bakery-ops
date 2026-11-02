<?php

declare(strict_types=1);

use Database\Seeders\Phase5Seeder;

/*
 * The poll budget (phase-5 plan 6, step 5.5d; 10.2 D15): how many requests a minute N devices ask of the server when every screen polls at
 * the web app's own cadences, visible and with nothing failing (the poll transport of ADR 0009, which the live host uses: no WebSockets).
 *
 * The cadences are one file, tests/Fixtures/perf/poll-profile.json: per kind of device (pad, KDS, till, admin board, shift screen) the
 * feeds its screen polls and how often. The k6 load test (scripts/perf/load.k6.js, CI job perf-load) polls from the same file, and
 * scripts/tests/test_poll_profile.py keeps every interval equal to the web constant it names (apps/web/src/lib/live/*.ts), so the
 * figure here, the load CI measures and the screens cannot drift apart.
 *
 * Two mixes: `ci`, the 60 devices perf-load runs (34 pads, 12 KDS, 8 tills, 6 admin and shift screens), enrolled by Phase5Seeder with
 * LOAD_DEVICES=60 in the same order; and `day_one`, the 12 tablets the shop is assumed to run on its first day (D15), the same mix scaled
 * to 12. docs/runbook/server.md states both figures next to the load average the shared host showed in Phase 4.
 */

/** @return array{kinds: array<string, array{device_kind: string, feeds: list<array{name: string, path: string, interval_ms: int}>}>, mixes: array<string, array<string, int>>} */
function pollProfile(): array
{
    $path = dirname(__DIR__, 2).'/Fixtures/perf/poll-profile.json';
    expect(is_file($path))->toBeTrue("the poll profile is missing: {$path}");

    return json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
}

/** Requests a minute one device of a kind makes: a feed polled every `interval_ms` asks 60 000 / interval_ms times a minute. */
function pollsPerMinute(array $kind): float
{
    return (float) array_sum(array_map(static fn (array $feed): float => 60_000 / $feed['interval_ms'], $kind['feeds']));
}

/** @param  array<string, int>  $mix  kind => how many devices */
function pollBudget(array $profile, array $mix): float
{
    $total = 0.0;
    foreach ($mix as $kind => $count) {
        $total += $count * pollsPerMinute($profile['kinds'][$kind]);
    }

    return $total;
}

it('runs the 60 devices of the CI load test in the order Phase5Seeder enrols them', function () {
    $profile = pollProfile();
    $mix = $profile['mixes']['ci'];

    expect($mix)->toBe(['pad' => 34, 'kds' => 12, 'till' => 8, 'admin' => 3, 'shift' => 3])
        ->and(array_sum($mix))->toBe(60);

    // load-01.. load-60: the kinds in the profile's order, each as the device kind the seeder enrols it as
    $kinds = [];
    foreach ($mix as $kind => $count) {
        array_push($kinds, ...array_fill(0, $count, $profile['kinds'][$kind]['device_kind']));
    }
    expect(Phase5Seeder::loadDeviceKinds(60))->toBe($kinds);
})->group('unit', 'gate:p5');

it('scales the same mix to the 12 tablets of day one', function () {
    $mix = pollProfile()['mixes']['day_one'];

    expect($mix)->toBe(['pad' => 7, 'kds' => 2, 'till' => 2, 'admin' => 1, 'shift' => 0])
        ->and(array_sum($mix))->toBe(12);
})->group('unit', 'gate:p5');

it('polls only conditionally, every feed at a cadence of 2 s or slower', function () {
    foreach (pollProfile()['kinds'] as $name => $kind) {
        expect($kind['feeds'])->not->toBeEmpty("{$name} polls nothing");
        foreach ($kind['feeds'] as $feed) {
            expect($feed['interval_ms'])->toBeInt()->toBeGreaterThanOrEqual(2000, "{$name}.{$feed['name']} polls faster than the web's fastest feed")
                ->and($feed['path'])->toStartWith('/');
        }
    }
})->group('unit', 'gate:p5');

it('computes the requests a minute each kind of device asks for', function (string $kind, int $perMinute) {
    expect(pollsPerMinute(pollProfile()['kinds'][$kind]))->toBe((float) $perMinute);
})->with([
    'a pad: its orders (2 s), the menu (5 s), the bell (15 s)' => ['pad', 46],
    'a KDS: tickets and the stock strip (2 s), the shift (5 s), the bell' => ['kds', 76],
    'a till: the queue and the display case (2 s), the menu, the production board, the shift and the cash (5 s), the counts and the bell (15 s)' => ['till', 116],
    'the admin board: every active order (2 s), the bell' => ['admin', 34],
    'a shift screen: the running shifts and the counts queue (5 s), the bell' => ['shift', 28],
])->group('unit', 'gate:p5');

it('states what the server is asked for at 12 tablets and at 60 devices', function () {
    $profile = pollProfile();
    $dayOne = pollBudget($profile, $profile['mixes']['day_one']);
    $ci = pollBudget($profile, $profile['mixes']['ci']);

    fwrite(STDERR, sprintf("[poll budget] 12 tablets (day one): %d requests a minute (%.1f a second); 60 devices (perf-load): %d a minute (%.1f a second)\n", $dayOne, $dayOne / 60, $ci, $ci / 60));

    // the figures docs/runbook/server.md quotes ("Poll budget")
    expect($dayOne)->toBe(740.0)
        ->and($ci)->toBe(3590.0);
})->group('unit', 'gate:p5');
