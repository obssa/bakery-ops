<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\Rebuild;
use App\Modules\Production\Models\ProductionBatch;
use App\Shared\Events\AnalyticsDayRebuilt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\AnalyticsDay;

/*
 * A rebuild is one transaction (phase-4 plan section 6, 4.4a; ADR 0014): a day is either its old rows or its new rows, never half of
 * each. The failure is planted in the middle of the writes, after daily_sales and the next tables were deleted and written again:
 * a query listener throws when the station metrics are inserted (no production seam is needed for the test). The old rows, the old
 * version and the old fingerprint are what is left; a first build that fails leaves no analytics_days row at all.
 */

beforeEach(function () {
    $this->d = AnalyticsDay::open();
    $this->croissant = $this->d->product();
    $sale = $this->d->sale('2026-11-20 06:00:00', [[$this->croissant, '2', '60.00']]);
    $this->d->pay($sale, 'cash', '120.00', '2026-11-20 06:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-21 03:00:00', 'UTC'));
    $this->boom = function (): void {
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'daily_station_metrics')) {
                throw new RuntimeException('the disk is full');
            }
        });
    };
    $this->rebuild = fn () => app(Rebuild::class)->day($this->d->branch->id, '2026-11-20', 'manual');
    // a station figure, so the station table is written
    ProductionBatch::factory()->finished()->create(['branch_id' => $this->d->branch->id, 'finished_at' => CarbonImmutable::parse('2026-11-20 05:00:00', 'UTC')]);
});

it('leaves the old rows, version and fingerprint when the rebuild fails half way through its writes', function () {
    $first = ($this->rebuild)();
    $before = AnalyticsDay::rows($this->d->branch->id, '2026-11-20');
    $late = $this->d->sale('2026-11-20 07:00:00', [[$this->croissant, '5', '60.00']]);
    $this->d->pay($late, 'cash', '300.00', '2026-11-20 07:00:00');
    ($this->boom)();

    expect(fn () => ($this->rebuild)())->toThrow(RuntimeException::class, 'the disk is full');

    $day = DB::table('analytics_days')->where('branch_id', $this->d->branch->id)->where('day', '2026-11-20')->first();
    expect(AnalyticsDay::rows($this->d->branch->id, '2026-11-20'))->toBe($before)
        ->and($before['daily_sales'][0]['revenue'])->toBe('120.00')
        ->and((int) $day->version)->toBe(1)
        ->and($day->fingerprint)->toBe($first->fingerprint);
})->group('module:analytics', 'feature');

it('leaves nothing at all when the first build of a day fails', function () {
    ($this->boom)();

    expect(fn () => ($this->rebuild)())->toThrow(RuntimeException::class, 'the disk is full');

    expect(DB::table('analytics_days')->count())->toBe(0);
    foreach (array_keys(AnalyticsDay::TABLES) as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }
})->group('module:analytics', 'feature');

it('announces nothing for a rebuild that rolled back', function () {
    Event::fake([AnalyticsDayRebuilt::class]);
    ($this->boom)();

    expect(fn () => ($this->rebuild)())->toThrow(RuntimeException::class);

    Event::assertNotDispatched(AnalyticsDayRebuilt::class);
})->group('module:analytics', 'feature');
