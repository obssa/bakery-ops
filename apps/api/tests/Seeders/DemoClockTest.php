<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DayPlan;
use Database\Seeders\Demo\DemoClock;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

pest()->group('feature', 'seeds');

/*
 * DemoClock (step 3.8, generalised in step 4.6a; phase-4 plan section 8): the days back of the anchor are a function of the phase. Below
 * Phase 4 the anchor stays three days back (so e2e-p0 to e2e-p3 and the Phase 3 seeder tests see exactly what they saw); from Phase 4
 * it is the history's days plus two (92 with the default 90, DEMO_HISTORY_DAYS / bakery.demo.history_days). A branch that holds data is
 * never anchored; from Phase 4 an order is data too.
 */

afterEach(function () {
    putenv('SEED_PHASE');
});

it('keeps the anchor three days back below Phase 4 and moves it to the history days plus two from Phase 4', function () {
    config(['bakery.demo.history_days' => 90]);
    foreach ([0, 1, 2, 3] as $phase) {
        expect(DemoClock::daysBackFor($phase))->toBe(3, "phase {$phase}");
    }
    expect(DayPlan::HISTORY_DAYS)->toBe(90)
        ->and(DemoClock::daysBackFor(4))->toBe(92)
        ->and(DemoClock::daysBackFor(5))->toBe(92);

    config(['bakery.demo.history_days' => 60]);
    expect(DemoClock::daysBackFor(3))->toBe(3)->and(DemoClock::daysBackFor(4))->toBe(62)->and(DayPlan::historyDays())->toBe(60);

    config(['bakery.demo.history_days' => 0]);
    expect(DayPlan::historyDays())->toBe(1);
});

it('anchors a Phase 3 run at 05:30 local three days back and a Phase 4 run at the history days plus two, then puts the clock back', function () {
    config(['bakery.demo.history_days' => 90]);
    $frozen = CarbonImmutable::parse('2026-10-27 09:15:00', 'UTC');
    $this->travelTo($frozen);
    $local = $frozen->setTimezone(DemoClock::TIMEZONE);

    $seen = [];
    foreach ([3 => 3, 4 => 92] as $phase => $back) {
        DemoClock::around($phase, function () use (&$seen, $phase): void {
            $seen[$phase] = [CarbonImmutable::now()->toIso8601String(), Carbon::now()->toIso8601String(), DemoClock::anchoredPhase(), DemoClock::playsPhaseFourHistory()];
        });
        $anchor = $local->subDays($back)->setTime(5, 30)->utc()->toIso8601String();
        expect($seen[$phase])->toBe([$anchor, $anchor, $phase, $phase >= 4], "phase {$phase}");
        // the frozen test clock is back, and nothing is anchored any more
        expect(CarbonImmutable::now()->equalTo($frozen))->toBeTrue()->and(DemoClock::anchored())->toBeFalse()->and(DemoClock::anchoredPhase())->toBeNull();
    }

    // below Phase 3 nothing is anchored
    DemoClock::around(2, function () use (&$seen): void {
        $seen[2] = DemoClock::anchored();
    });
    expect($seen[2])->toBeFalse();
});

it('does not anchor a branch that holds data: Phase 3 looks at movements and shifts, Phase 4 at orders too', function () {
    expect(DemoClock::wantsAnchor(2))->toBeFalse()
        ->and(DemoClock::wantsAnchor(3))->toBeTrue()
        ->and(DemoClock::wantsAnchor(4))->toBeTrue();

    putenv('SEED_PHASE=1');
    $this->seed(DemoSeeder::class);

    expect(DemoClock::wantsAnchor(3))->toBeFalse()->and(DemoClock::wantsAnchor(4))->toBeFalse();
});

it('seeds Phase 3 three days back exactly as before, whatever DEMO_HISTORY_DAYS says', function () {
    config(['bakery.demo.history_days' => 60]);
    putenv('SEED_PHASE=3');
    $this->seed(DemoSeeder::class);

    $zone = DemoClock::TIMEZONE;
    $oldest = CarbonImmutable::parse((string) DB::table('stock_movements')->min('created_at'), 'UTC')->setTimezone($zone);
    expect($oldest->toDateString())->toBe(CarbonImmutable::now($zone)->subDays(3)->toDateString())
        ->and($oldest->format('H:i'))->toBe('05:30')
        ->and(DB::table('analytics_days')->where('trigger', 'seed')->count())->toBe(0);
});
