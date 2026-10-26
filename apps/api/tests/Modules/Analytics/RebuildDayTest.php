<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\Rebuild;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\ItemCostHistory;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Modules\Production\Models\ProductionBatch;
use App\Shared\Tenancy\BranchNotResolved;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AnalyticsDay;
use Tests\Support\OrderFloor;
use Tests\Support\Receipts;

/*
 * Rebuild::day against a hand-built oracle (phase-4 plan section 6, 4.4a; section 7). The fixture day is made through the real actions
 * where the ledger or the drawer is touched (the three shift rules are on: OrderFloor opens the kitchen, bar and till shifts the day
 * before, and they are still running): orders sent by the waiter, station lines accepted and readied by the chef and the barista,
 * served, paid at the till in cash and Telebirr; stock wasted and bought through the Ledger. What no action writes yet is a row: a
 * void and a cancellation, a refund (4.3d), a finished batch, a stock-out episode, the cost history. Every column of every table is
 * then compared with figures worked out by hand below.
 *
 * The day D is 2026-11-20 in Africa/Addis_Ababa (UTC+3). The menu (OrdersMenu) and its stock are made on D-1.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->travelTo(CarbonImmutable::parse('2026-11-19 07:00:00', 'UTC'));
    $this->f = OrderFloor::open($this);
    $m = $this->f->menu;
    foreach ([[$m->bread, '3.5000'], [$m->chicken, '0.4500'], [$m->lettuce, '0.1000'], [$m->onion, '0.0500'], [$m->butter, '0.2000'],
        [$m->espresso, '1.0000'], [$m->milk, '0.0200'], [$m->water, '18.0000']] as [$item, $cost]) {
        ItemCostHistory::factory()->create(['branch_id' => $this->f->branch->id, 'item_id' => $item->id, 'cost_after' => $cost, 'created_at' => CarbonImmutable::now()]);
    }
    $this->at = fn (string $utc) => $this->travelTo(CarbonImmutable::parse("2026-11-20 {$utc}", 'UTC'));
});

it('writes every column of every summary table of the day as worked out by hand', function () {
    $f = $this->f;
    $m = $f->menu;

    // 09:00 local: two sandwiches and a croissant; the kitchen takes ten minutes; paid in cash at 09:15
    ($this->at)('06:00:00');
    $a = $f->send([$m->line($m->sandwich, '2'), $m->line($m->croissant)]);
    $sandwich = $f->lineOf($a, $m->sandwich)['id'];
    ($this->at)('06:01:00');
    $f->act('accept', $sandwich, $f->chef)->assertOk();
    ($this->at)('06:11:00');
    $f->act('ready', $sandwich, $f->chef)->assertOk();
    ($this->at)('06:12:00');
    $f->act('served', $sandwich, $f->waiter)->assertOk();
    $f->act('served', $f->lineOf($a, $m->croissant)['id'], $f->waiter)->assertOk();
    ($this->at)('06:15:00');
    Receipts::pay($this, $f, $a['id'], 'cash', '560.00', ['tendered_amount' => '600.00'])->assertCreated();

    // 07:00Z a delivery of water; 08:00Z the kitchen throws 50 g of lettuce away
    ($this->at)('07:00:00');
    stockIn($m->water, $m->display, '12');
    ($this->at)('08:00:00');
    stockIn($m->lettuce, $m->kitchen, '-50', MovementReason::Waste);

    // 13:00 local: a latte (three minutes at the bar) and two waters, split over Telebirr and cash
    ($this->at)('10:00:00');
    $b = $f->send([$m->line($m->latte), $m->line($m->water, '2')]);
    $latte = $f->lineOf($b, $m->latte)['id'];
    ($this->at)('10:01:00');
    $f->act('accept', $latte, $f->barista)->assertOk();
    ($this->at)('10:04:00');
    $f->act('ready', $latte, $f->barista)->assertOk();
    ($this->at)('10:05:00');
    $f->act('served', $latte, $f->waiter)->assertOk();
    $f->act('served', $f->lineOf($b, $m->water)['id'], $f->waiter)->assertOk();
    ($this->at)('10:06:00');
    Receipts::pay($this, $f, $b['id'], 'telebirr', '100.00', ['reference_no' => 'FT111'])->assertCreated();
    ($this->at)('10:07:00');
    Receipts::pay($this, $f, $b['id'], 'cash', '80.00', ['tendered_amount' => '80.00'])->assertCreated();

    // rows no action writes yet: a void the manager asked for, a cancelled cart, a cash refund of 60.00 of the first order (4.3d),
    // a batch finished in the cake room, the cake running out at the display case
    $b1 = $f->branch->id;
    Order::factory()->create(['branch_id' => $b1, 'status' => OrderStatus::Voided, 'subtotal' => '90.00', 'total' => '90.00',
        'voided_at' => CarbonImmutable::parse('2026-11-20 11:00:00', 'UTC'), 'voided_by_user_id' => $f->manager->id]);
    Order::factory()->create(['branch_id' => $b1, 'status' => OrderStatus::Cancelled, 'cancelled_at' => CarbonImmutable::parse('2026-11-20 11:30:00', 'UTC')]);
    $cashA = Payment::query()->where('order_id', $a['id'])->firstOrFail();
    Receipts::refund($cashA, '60.00', CarbonImmutable::parse('2026-11-20 13:00:00', 'UTC'), $f->cashier->id);
    $batch = ProductionBatch::factory()->finished()->create(['branch_id' => $b1, 'produced_qty' => '12', 'waste_qty' => '1',
        'finished_at' => CarbonImmutable::parse('2026-11-20 05:00:00', 'UTC')]);
    DB::table('stock_alert_states')->insert([
        'branch_id' => $b1, 'item_id' => $m->cake->id, 'location_id' => $m->display->id, 'location_key' => $m->display->id, 'type' => 'out',
        'is_active' => 1, 'active_since' => '2026-11-20 09:00:00', 'cleared_at' => null, 'available_at_start' => '0.0000',
        'created_at' => '2026-11-20 09:00:00', 'updated_at' => '2026-11-20 09:00:00',
    ]);

    // step 4.4b: the payments and the orders paid have touched the day already (the queue is sync here), before the rows above
    $touched = DB::table('analytics_days')->where('branch_id', $b1)->where('day', '2026-11-20')->first();
    expect($touched?->trigger)->toBe('touch')->and((int) $touched?->version)->toBeGreaterThan(0);

    $this->travelTo(CarbonImmutable::parse('2026-11-21 03:00:00', 'UTC'));
    $result = app(Rebuild::class)->day($b1, '2026-11-20', 'manual');

    $D = '2026-11-20';
    $cash = (int) PaymentChannel::query()->where('code', 'cash')->value('id');
    $telebirr = (int) PaymentChannel::query()->where('code', 'telebirr')->value('id');
    $zeroItem = [
        'units_sold_qty' => '0.0000', 'refunded_qty' => '0.0000', 'produced_qty' => '0.0000', 'consumed_qty' => '0.0000', 'waste_qty' => '0.0000',
        'purchased_qty' => '0.0000', 'adjusted_qty' => '0.0000', 'stockout_count' => 0, 'closing_on_hand_qty' => '0.0000', 'revenue' => '0.00',
        'cogs_total' => '0.00', 'margin_total' => '0.00', 'closing_value_total' => '0.00', 'cogs_complete' => true,
    ];
    $item = fn ($it, string $kind, array $figures): array => ['day' => $D, 'item_id' => $it->id, 'category_id' => null, 'kind' => $kind] + array_merge($zeroItem, $figures);
    $station = fn (int $id, array $figures): array => array_merge([
        'day' => $D, 'station_id' => $id, 'tickets_count' => 0, 'lines_served' => 0, 'lines_rejected' => 0, 'avg_prep_seconds' => null, 'p90_prep_seconds' => null,
        'batches_finished' => 0, 'produced_qty' => '0.0000', 'waste_qty' => '0.0000', 'shifts_count' => 0, 'shifts_locked_count' => 0,
        'variance_line_count' => 0, 'cash_variance_total' => '0.00', 'stockout_count' => 0,
    ], $figures);
    $person = fn ($user, string $kind, array $figures): array => array_merge([
        'day' => $D, 'user_id' => $user->id, 'role_kind' => $kind, 'orders_count' => 0, 'items_qty' => '0.0000', 'revenue' => '0.00', 'payments_count' => 0,
        'payments_amount' => '0.00', 'voids_count' => 0, 'refunds_count' => 0, 'lines_prepared' => 0, 'avg_prep_seconds' => null,
    ], $figures);

    expect(AnalyticsDay::rows($b1, $D))->toBe([
        'daily_sales' => [[
            'day' => $D, 'orders_count' => 2, 'items_qty' => '6.0000', 'subtotal' => '740.00', 'discount_total' => '0.00', 'tax_total' => '0.00',
            'revenue' => '740.00', 'avg_ticket' => '370.00', 'voided_count' => 1, 'voided_total' => '90.00', 'cancelled_count' => 1, 'refund_count' => 1,
            'refund_total' => '60.00', 'net_revenue' => '680.00', 'dine_in_revenue' => '0.00', 'takeaway_revenue' => '740.00', 'delivery_revenue' => '0.00',
            'advance_revenue' => '0.00',
        ]],
        'daily_hour_sales' => [
            ['day' => $D, 'hour' => 9, 'orders_count' => 1, 'items_qty' => '3.0000', 'revenue' => '560.00'],
            ['day' => $D, 'hour' => 13, 'orders_count' => 1, 'items_qty' => '3.0000', 'revenue' => '180.00'],
        ],
        'daily_channel_totals' => [
            ['day' => $D, 'channel_id' => $cash, 'receipts_count' => 2, 'amount' => '640.00', 'refund_count' => 1, 'refund_amount' => '60.00', 'net_amount' => '580.00',
                'without_reference_count' => 2, 'without_photo_count' => 2, 'verified_count' => 0, 'disputed_count' => 0, 'duplicate_reference_count' => 0],
            ['day' => $D, 'channel_id' => $telebirr, 'receipts_count' => 1, 'amount' => '100.00', 'refund_count' => 0, 'refund_amount' => '0.00', 'net_amount' => '100.00',
                'without_reference_count' => 0, 'without_photo_count' => 0, 'verified_count' => 0, 'disputed_count' => 0, 'duplicate_reference_count' => 0],
        ],
        'daily_item_metrics' => [
            // two sandwiches at READY: 252 g chicken (126 g each with the 5 % waste), 40 g lettuce, 30 g onion, 20 g butter, 4 slices
            $item($m->chicken, 'ingredient', ['consumed_qty' => '252.0000', 'closing_on_hand_qty' => '748.0000', 'closing_value_total' => '336.60']),
            $item($m->lettuce, 'ingredient', ['consumed_qty' => '40.0000', 'waste_qty' => '50.0000', 'closing_on_hand_qty' => '210.0000', 'closing_value_total' => '21.00']),
            $item($m->onion, 'ingredient', ['consumed_qty' => '30.0000', 'closing_on_hand_qty' => '170.0000', 'closing_value_total' => '8.50']),
            // the optional tomato is not used and has no cost: valued at 0
            $item($m->tomato, 'ingredient', ['closing_on_hand_qty' => '100.0000']),
            $item($m->butter, 'ingredient', ['consumed_qty' => '20.0000', 'closing_on_hand_qty' => '480.0000', 'closing_value_total' => '96.00']),
            $item($m->espresso, 'ingredient', ['consumed_qty' => '18.0000', 'closing_on_hand_qty' => '182.0000', 'closing_value_total' => '182.00']),
            $item($m->milk, 'ingredient', ['consumed_qty' => '220.0000', 'closing_on_hand_qty' => '780.0000', 'closing_value_total' => '15.60']),
            $item($m->bread, 'product', ['consumed_qty' => '4.0000', 'closing_on_hand_qty' => '16.0000', 'closing_value_total' => '56.00']),
            // 2 x (2 x 3.50 + 126 x 0.45 + 20 x 0.10 + 15 x 0.05 + 10 x 0.20) = 2 x 68.45
            $item($m->sandwich, 'product', ['units_sold_qty' => '2.0000', 'revenue' => '500.00', 'cogs_total' => '136.90', 'margin_total' => '363.10']),
            // 18 g x 1.00 + 220 ml x 0.02
            $item($m->latte, 'product', ['units_sold_qty' => '1.0000', 'revenue' => '120.00', 'cogs_total' => '22.40', 'margin_total' => '97.60']),
            // from the display case, no recipe version on the line and no cost of its own: never estimated
            $item($m->croissant, 'product', ['units_sold_qty' => '1.0000', 'closing_on_hand_qty' => '4.0000', 'revenue' => '60.00', 'margin_total' => '60.00', 'cogs_complete' => false]),
            $item($m->cake, 'product', ['stockout_count' => 1, 'closing_on_hand_qty' => '1.0000']),
            $item($m->water, 'product', ['units_sold_qty' => '2.0000', 'purchased_qty' => '12.0000', 'closing_on_hand_qty' => '34.0000', 'revenue' => '60.00',
                'cogs_total' => '36.00', 'margin_total' => '24.00', 'closing_value_total' => '612.00']),
        ],
        'daily_station_metrics' => [
            $station($m->kitchenStation->id, ['tickets_count' => 1, 'lines_served' => 1, 'avg_prep_seconds' => 600, 'p90_prep_seconds' => 600, 'shifts_count' => 1]),
            $station($m->barStation->id, ['tickets_count' => 1, 'lines_served' => 1, 'avg_prep_seconds' => 180, 'p90_prep_seconds' => 180, 'shifts_count' => 1]),
            $station($m->tillStation->id, ['shifts_count' => 1, 'stockout_count' => 1]),
            $station($batch->station_id, ['batches_finished' => 1, 'produced_qty' => '12.0000', 'waste_qty' => '1.0000']),
        ],
        'daily_user_metrics' => [
            $person($f->manager, 'waiter', ['voids_count' => 1]),
            $person($f->cashier, 'cashier', ['payments_count' => 3, 'payments_amount' => '740.00', 'refunds_count' => 1]),
            $person($f->waiter, 'waiter', ['orders_count' => 2, 'items_qty' => '6.0000', 'revenue' => '740.00']),
            $person($f->chef, 'station', ['lines_prepared' => 1, 'avg_prep_seconds' => 600]),
            $person($f->barista, 'station', ['lines_prepared' => 1, 'avg_prep_seconds' => 180]),
        ],
    ]);

    $day = DB::table('analytics_days')->where('branch_id', $b1)->where('day', $D)->first();
    $counts = ['daily_sales' => 1, 'daily_hour_sales' => 2, 'daily_channel_totals' => 2, 'daily_item_metrics' => 13, 'daily_station_metrics' => 4, 'daily_user_metrics' => 5];
    expect($result->rows)->toBe($counts)
        ->and($result->version)->toBe((int) $touched->version + 1)
        ->and($result->changed)->toBeTrue('the rows written after the last touch changed the day')
        ->and($result->durationMs)->toBeGreaterThanOrEqual(0)
        ->and($result->fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and((int) $day->version)->toBe($result->version)
        ->and($day->trigger)->toBe('manual')
        ->and((bool) $day->changed)->toBeTrue()
        ->and($day->fingerprint)->toBe($result->fingerprint)
        ->and(json_decode((string) $day->row_counts, true))->toBe($counts)
        ->and(AnalyticsDay::rebuiltAts($b1, $D))->toHaveCount(1, 'every row of the rebuild carries the one instant it was written at');
    // nothing of the day before or after was written
    expect(AnalyticsDay::rows($b1, '2026-11-19')['daily_sales'])->toBe([])->and(AnalyticsDay::rows($b1, '2026-11-21')['daily_sales'])->toBe([]);
})->group('module:analytics', 'feature');

it('reads only its own branch, whatever branch the caller is in', function () {
    $f = $this->f;
    ($this->at)('06:00:00');
    $order = $f->send([$f->menu->line($f->menu->water)]);
    $f->act('served', $order['lines'][0]['id'], $f->waiter)->assertOk();
    Receipts::pay($this, $f, $order['id'], 'cash', '30.00', ['tendered_amount' => '30.00'])->assertCreated();

    $elsewhere = AnalyticsDay::open();
    $sale = $elsewhere->sale('2026-11-20 07:00:00', [[$elsewhere->product(), '1', '999.00']]);
    $elsewhere->pay($sale, 'cash', '999.00', '2026-11-20 07:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-21 03:00:00', 'UTC'));
    app(Rebuild::class)->day($f->branch->id, '2026-11-20', 'manual');

    expect(app(CurrentBranch::class)->id())->toBe($elsewhere->branch->id, 'the caller\'s branch is put back')
        ->and(AnalyticsDay::rows($f->branch->id, '2026-11-20')['daily_sales'][0]['revenue'])->toBe('30.00')
        ->and(AnalyticsDay::rows($elsewhere->branch->id, '2026-11-20')['daily_sales'])->toBe([], 'the other branch was not rebuilt')
        ->and(DB::table('daily_sales')->count())->toBe(1);
})->group('module:analytics', 'feature');

it('refuses an unknown trigger, a malformed day, a day after today and a branch that does not exist, writing nothing', function () {
    $rebuild = app(Rebuild::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 03:00:00', 'UTC')); // 06:00 local on the 21st

    expect(fn () => $rebuild->day($this->f->branch->id, '2026-11-20', 'cron'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $rebuild->day($this->f->branch->id, '2026-11-31', 'manual'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $rebuild->day($this->f->branch->id, '2026-11-22', 'manual'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $rebuild->day(999_999, '2026-11-20', 'manual'))->toThrow(BranchNotResolved::class);
    expect(DB::table('analytics_days')->count())->toBe(0)->and($rebuild->day($this->f->branch->id, '2026-11-21', 'manual')->version)->toBe(1, 'today may be built');
})->group('module:analytics', 'feature');
