<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\Rebuild;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\AnalyticsDay;
use Tests\Support\SeededGate;

pest()->group('gate:p4');

/*
 * Gate criterion 4 of Phase 4, the second half (phase-4 plan 10.3; ADR 0014: Rebuild::day is a pure function of one local day's facts,
 * idempotent by fingerprint), on the seeded history (Tests\Support\SeededGate): three seeded days, chosen for what they hold (the busiest
 * day, a day with a voided payment, and a Friday, whose Night shift runs into Saturday), are rebuilt twice each. Both rebuilds write the
 * rows the seed wrote (every column but rebuilt_at) and the seed's fingerprint; neither is `changed`; only the version moves.
 */

it('rebuilds three seeded days twice into the seed\'s own rows and fingerprint; only the version moves', function () {
    $gate = SeededGate::open();
    $b = $gate->branch->id;
    $zone = $gate->branch->timezone;
    $first = $gate->window[0];
    $last = $gate->window[count($gate->window) - 1];
    [$windowStart] = $gate->bounds($first);
    [, $windowEnd] = $gate->bounds($last);

    $busiest = (string) DB::table('daily_sales')->where('branch_id', $b)->whereBetween('day', [$first, $last])
        ->orderByDesc('orders_count')->orderBy('day')->value('day');
    $voidedAt = DB::table('payments')->where('branch_id', $b)->where('status', 'voided')
        ->where('received_at', '>=', $windowStart)->where('received_at', '<', $windowEnd)->orderBy('id')->value('received_at');
    $fridays = array_values(array_filter($gate->window, fn (string $day): bool => CarbonImmutable::parse($day, $zone)->isFriday()));
    $picked = array_values(array_unique(array_filter([
        substr($busiest, 0, 10),
        $voidedAt === null ? null : CarbonImmutable::parse((string) $voidedAt, 'UTC')->setTimezone($zone)->toDateString(),
        $fridays === [] ? null : $fridays[count($fridays) - 1],
    ])));
    foreach (array_reverse($gate->window) as $day) {
        if (count($picked) >= 3) {
            break;
        }
        $picked = array_values(array_unique([...$picked, $day]));
    }
    expect($picked)->toHaveCount(3);

    foreach ($picked as $day) {
        $seeded = DB::table('analytics_days')->where('branch_id', $b)->where('day', $day)->first();
        $rows = AnalyticsDay::rows($b, $day);
        expect($rows['daily_sales'])->toHaveCount(1, "{$day} was seeded with its sales");

        $once = app(Rebuild::class)->day($b, $day, 'manual');
        $afterOnce = AnalyticsDay::rows($b, $day);
        $twice = app(Rebuild::class)->day($b, $day, 'manual');
        $afterTwice = AnalyticsDay::rows($b, $day);

        expect($afterOnce)->toBe($rows, "{$day}: the first rebuild wrote other rows than the seed")
            ->and($afterTwice)->toBe($rows, "{$day}: the second rebuild wrote other rows than the seed")
            ->and($once->fingerprint)->toBe((string) $seeded->fingerprint)
            ->and($twice->fingerprint)->toBe((string) $seeded->fingerprint)
            ->and([$once->changed, $twice->changed])->toBe([false, false])
            ->and([$once->version, $twice->version])->toBe([(int) $seeded->version + 1, (int) $seeded->version + 2])
            ->and($twice->rows)->toBe($once->rows);
        fwrite(STDERR, sprintf("\n[gate:p4] rebuild %s twice: %d rows, fingerprint %s unchanged", $day, array_sum($once->rows), substr($once->fingerprint, 0, 12)));
    }
});
