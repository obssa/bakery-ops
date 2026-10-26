<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\Rebuild;
use App\Shared\Events\AnalyticsDayRebuilt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\AnalyticsDay;

/*
 * Rebuild::day is idempotent (phase-4 plan section 6, 4.4a; ADR 0014): two rebuilds of unchanged facts write identical rows and the
 * same fingerprint, and only version, rebuilt_at and `changed` move. A rebuild replaces the day: what the facts no longer hold is
 * gone (a payment voided since, a channel that took nothing any more), nothing is doubled, and other days are not touched.
 */

beforeEach(function () {
    $this->d = AnalyticsDay::open();
    $this->croissant = $this->d->product();
    $this->sale = $this->d->sale('2026-11-20 06:00:00', [[$this->croissant, '3', '60.00']]);
    $this->cash = $this->d->pay($this->sale, 'cash', '100.00', '2026-11-20 06:00:00');
    $this->telebirr = $this->d->pay($this->sale, 'telebirr', '80.00', '2026-11-20 06:01:00', ['reference_no' => 'FT1', 'reference_norm' => 'FT1']);
    $this->other = $this->d->sale('2026-11-21 06:00:00', [[$this->croissant, '1', '60.00']]);
    $this->travelTo(CarbonImmutable::parse('2026-11-22 03:00:00', 'UTC'));
    $this->rebuild = fn (string $day = '2026-11-20', string $trigger = 'manual') => app(Rebuild::class)->day($this->d->branch->id, $day, $trigger);
});

it('writes the same rows and the same fingerprint twice; only version, rebuilt_at and changed move', function () {
    $first = ($this->rebuild)();
    $rows = AnalyticsDay::rows($this->d->branch->id, '2026-11-20');
    $firstAt = AnalyticsDay::rebuiltAts($this->d->branch->id, '2026-11-20');
    $this->travel(5)->minutes();

    $second = ($this->rebuild)('2026-11-20', 'nightly');

    expect(AnalyticsDay::rows($this->d->branch->id, '2026-11-20'))->toBe($rows)
        ->and($second->fingerprint)->toBe($first->fingerprint)
        ->and([$first->version, $second->version])->toBe([1, 2])
        ->and([$first->changed, $second->changed])->toBe([true, false])
        ->and($second->rows)->toBe($first->rows)
        ->and(AnalyticsDay::rebuiltAts($this->d->branch->id, '2026-11-20'))->not->toBe($firstAt);
    $day = DB::table('analytics_days')->where('branch_id', $this->d->branch->id)->where('day', '2026-11-20')->first();
    expect((int) $day->version)->toBe(2)->and($day->trigger)->toBe('nightly')->and((bool) $day->changed)->toBeFalse()->and($day->fingerprint)->toBe($first->fingerprint);
})->group('module:analytics', 'feature');

it('replaces the day: a payment voided since is gone, its channel row with it, and nothing is doubled', function () {
    ($this->rebuild)();
    expect(AnalyticsDay::rows($this->d->branch->id, '2026-11-20')['daily_channel_totals'])->toHaveCount(2);

    DB::table('payments')->where('id', $this->telebirr->id)->update(['status' => 'voided', 'voided_at' => '2026-11-20 07:00:00']);
    $result = ($this->rebuild)();

    $rows = AnalyticsDay::rows($this->d->branch->id, '2026-11-20');
    expect($result->changed)->toBeTrue()
        ->and(array_column($rows['daily_channel_totals'], 'channel_id'))->toBe([$this->d->channel('cash')])
        ->and($rows['daily_sales'])->toHaveCount(1)
        ->and(DB::table('daily_sales')->where('branch_id', $this->d->branch->id)->count())->toBe(1, 'the 21st was never built');
})->group('module:analytics', 'feature');

it('picks up a fact that arrived since, and leaves the other days alone', function () {
    ($this->rebuild)('2026-11-21');
    $before21 = AnalyticsDay::rows($this->d->branch->id, '2026-11-21');
    $first = ($this->rebuild)();

    $late = $this->d->sale('2026-11-20 20:59:59', [[$this->croissant, '1', '60.00']]);
    $this->d->pay($late, 'cash', '60.00', '2026-11-20 20:59:59');
    $again = ($this->rebuild)();

    $sales = AnalyticsDay::rows($this->d->branch->id, '2026-11-20')['daily_sales'][0];
    expect($again->fingerprint)->not->toBe($first->fingerprint)
        ->and($again->changed)->toBeTrue()
        ->and($sales['orders_count'])->toBe(2)
        ->and($sales['revenue'])->toBe('240.00')
        ->and(AnalyticsDay::rows($this->d->branch->id, '2026-11-21'))->toBe($before21);
})->group('module:analytics', 'feature');

it('announces the rebuilt day after the commit (AnalyticsDayRebuilt: day, trigger, version, changed)', function () {
    Event::fake([AnalyticsDayRebuilt::class]);

    ($this->rebuild)('2026-11-20', 'seed');
    ($this->rebuild)('2026-11-20', 'touch');

    $seen = [];
    Event::assertDispatched(AnalyticsDayRebuilt::class, function (AnalyticsDayRebuilt $event) use (&$seen): bool {
        $seen[] = [$event->branchId, $event->payload()];

        return true;
    });
    expect($seen)->toBe([
        [$this->d->branch->id, ['day' => '2026-11-20', 'trigger' => 'seed', 'version' => 1, 'changed' => true]],
        [$this->d->branch->id, ['day' => '2026-11-20', 'trigger' => 'touch', 'version' => 2, 'changed' => false]],
    ]);
})->group('module:analytics', 'feature');
