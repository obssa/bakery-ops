<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\Rebuild;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Tests\Support\AnalyticsDay;

/*
 * The day boundary (phase-4 plan 10.5; ADR 0014; traps.md "Time zone"). Days are LOCAL (Africa/Addis_Ababa, UTC+3): 20:59:59Z is
 * 23:59:59 on the 20th and 21:00:00Z is already the 21st. An order settled a second before midnight is the 20th's at hour 23; one
 * settled a minute after is the 21st's at hour 0; payments follow their own received_at. A Night shift (22:00 on the 20th to 06:30 on
 * the 21st) is counted on both days it lived, its variance only on its date; the days before and after know nothing of any of it.
 */

beforeEach(function () {
    $this->d = AnalyticsDay::open();
    $croissant = $this->d->product();
    $late = $this->d->sale('2026-11-20 20:59:59', [[$croissant, '1', '60.00']]);
    $this->d->pay($late, 'cash', '60.00', '2026-11-20 20:59:59');
    $early = $this->d->sale('2026-11-20 21:01:00', [[$croissant, '2', '60.00']]);
    $this->d->pay($early, 'cash', '120.00', '2026-11-20 21:00:00');
    $bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $this->d->branch->id]);
    $this->bakery = $bakery;
    $night = ShiftInstance::factory()->locked()->create([
        'branch_id' => $this->d->branch->id, 'station_id' => $bakery->id, 'date' => '2026-11-20',
        'opened_at' => CarbonImmutable::parse('2026-11-20 19:00:00', 'UTC'), 'submitted_at' => CarbonImmutable::parse('2026-11-21 03:00:00', 'UTC'),
        'locked_at' => CarbonImmutable::parse('2026-11-21 03:30:00', 'UTC'),
    ]);
    $oven = stockLocation($this->d->branch, LocationType::Bakery, $bakery);
    $count = StockCount::factory()->forShift($night, $oven->id)->closing()->status(CountStatus::Approved)->create();
    StockCountLine::factory()->forCount($count, Item::factory()->ingredient('kg')->create(['branch_id' => $this->d->branch->id])->id)->counted('9', '10')->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-23 03:00:00', 'UTC'));
    foreach (['2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22'] as $day) {
        app(Rebuild::class)->day($this->d->branch->id, $day, 'manual');
    }
    $this->rows = fn (string $day): array => AnalyticsDay::rows($this->d->branch->id, $day);
});

it('puts an order settled at 23:59:59 local on that day, hour 23, and one settled at 00:01 on the next, hour 0', function () {
    $d20 = ($this->rows)('2026-11-20');
    $d21 = ($this->rows)('2026-11-21');

    expect([$d20['daily_sales'][0]['orders_count'], $d20['daily_sales'][0]['revenue']])->toBe([1, '60.00'])
        ->and([$d21['daily_sales'][0]['orders_count'], $d21['daily_sales'][0]['revenue']])->toBe([1, '120.00'])
        ->and(array_column($d20['daily_hour_sales'], 'hour'))->toBe([23])
        ->and(array_column($d21['daily_hour_sales'], 'hour'))->toBe([0])
        ->and($d20['daily_channel_totals'][0]['amount'])->toBe('60.00')
        ->and($d21['daily_channel_totals'][0]['amount'])->toBe('120.00', 'the payment at 00:00:00 local is the 21st\'s');
})->group('module:analytics', 'feature');

it('counts a Night shift on both days it lived and on no other; its variance belongs to its date only', function () {
    $station = fn (string $day): array => array_values(array_filter(($this->rows)($day)['daily_station_metrics'], fn (array $r): bool => $r['station_id'] === $this->bakery->id));

    expect($station('2026-11-20'))->toHaveCount(1)
        ->and($station('2026-11-20')[0]['shifts_count'])->toBe(1)
        ->and($station('2026-11-20')[0]['shifts_locked_count'])->toBe(1)
        ->and($station('2026-11-20')[0]['variance_line_count'])->toBe(1)
        ->and($station('2026-11-21'))->toHaveCount(1)
        ->and($station('2026-11-21')[0]['shifts_count'])->toBe(1)
        ->and($station('2026-11-21')[0]['variance_line_count'])->toBe(0)
        ->and($station('2026-11-19'))->toBe([])
        ->and($station('2026-11-22'))->toBe([]);
})->group('module:analytics', 'feature');

it('leaves the day before and the day after empty but built', function () {
    foreach (['2026-11-19', '2026-11-22'] as $day) {
        $rows = ($this->rows)($day);
        expect($rows['daily_sales'][0]['orders_count'])->toBe(0, $day)
            ->and($rows['daily_hour_sales'])->toBe([], $day)
            ->and($rows['daily_channel_totals'])->toBe([], $day)
            ->and($rows['daily_user_metrics'])->toBe([], $day);
    }
})->group('module:analytics', 'feature');
