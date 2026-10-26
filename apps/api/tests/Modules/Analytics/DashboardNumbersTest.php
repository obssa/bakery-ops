<?php

declare(strict_types=1);

use App\Modules\Analytics\Contracts\Rebuild;
use App\Modules\Inventory\Models\ItemCostHistory;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OrderFloor;
use Tests\Support\Receipts;

pest()->group('module:analytics', 'feature');

/*
 * The dashboards' figures against an oracle (phase-4 plan section 6, 4.4c): two local days made through the real actions (the three shift
 * rules are on; OrderFloor opens the kitchen, bar and till shifts the day before and they keep running), orders sent by the waiter,
 * station lines accepted and readied by the chef and the barista, paid at the till in cash and Telebirr; a void and a refund as rows
 * (nothing writes them through an action yet, 4.3d), both days rebuilt. Every figure each endpoint answers is then compared with a sum
 * worked out here straight from orders, order_lines, payments, payment_refunds and shift_instances, with brick/math (never a float), on the
 * clocks of ADR 0014: orders by settled_at, payments by received_at, refunds by refunded_at, prep by ready_at, all in local days.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->travelTo(CarbonImmutable::parse('2026-11-19 07:00:00', 'UTC'));
    $this->f = OrderFloor::open($this);
    $f = $this->f;
    $m = $f->menu;
    foreach ([[$m->bread, '3.5000'], [$m->chicken, '0.4500'], [$m->lettuce, '0.1000'], [$m->onion, '0.0500'], [$m->butter, '0.2000'],
        [$m->espresso, '1.0000'], [$m->milk, '0.0200'], [$m->water, '18.0000']] as [$item, $cost]) {
        ItemCostHistory::factory()->create(['branch_id' => $f->branch->id, 'item_id' => $item->id, 'cost_after' => $cost, 'created_at' => CarbonImmutable::now()]);
    }
    $at = fn (string $utc) => $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    $made = function (array $order, $item, $by, string $accept, string $ready, string $served) use ($f, $at): void {
        $line = $f->lineOf($order, $item)['id'];
        $at($accept);
        $f->act('accept', $line, $by)->assertOk();
        $at($ready);
        $f->act('ready', $line, $by)->assertOk();
        $at($served);
        $f->act('served', $line, $f->waiter)->assertOk();
    };

    // Friday the 20th: two sandwiches and a croissant at 09:00 local, paid in cash; a latte and two waters at 13:00, split over Telebirr and cash
    $at('2026-11-20 06:00:00');
    $a = $f->send([$m->line($m->sandwich, '2'), $m->line($m->croissant)]);
    $made($a, $m->sandwich, $f->chef, '2026-11-20 06:01:00', '2026-11-20 06:11:00', '2026-11-20 06:12:00');
    $f->act('served', $f->lineOf($a, $m->croissant)['id'], $f->waiter)->assertOk();
    $at('2026-11-20 06:15:00');
    Receipts::pay($this, $f, $a['id'], 'cash', '560.00', ['tendered_amount' => '600.00'])->assertCreated();
    $at('2026-11-20 10:00:00');
    $b = $f->send([$m->line($m->latte), $m->line($m->water, '2')]);
    $made($b, $m->latte, $f->barista, '2026-11-20 10:01:00', '2026-11-20 10:04:00', '2026-11-20 10:05:00');
    $f->act('served', $f->lineOf($b, $m->water)['id'], $f->waiter)->assertOk();
    $at('2026-11-20 10:06:00');
    Receipts::pay($this, $f, $b['id'], 'telebirr', '100.00', ['reference_no' => 'FT111'])->assertCreated();
    $at('2026-11-20 10:07:00');
    Receipts::pay($this, $f, $b['id'], 'cash', '80.00', ['tendered_amount' => '80.00'])->assertCreated();
    // rows no action writes yet: an order the manager voided, a cash refund of 60.00 of the first order (4.3d)
    Order::factory()->create(['branch_id' => $f->branch->id, 'status' => OrderStatus::Voided, 'subtotal' => '90.00', 'total' => '90.00',
        'voided_at' => CarbonImmutable::parse('2026-11-20 11:00:00', 'UTC'), 'voided_by_user_id' => $f->manager->id]);
    Receipts::refund(Payment::query()->where('order_id', $a['id'])->firstOrFail(), '60.00', CarbonImmutable::parse('2026-11-20 13:00:00', 'UTC'), $f->cashier->id);

    // Saturday the 21st: three croissants and a water at 08:30 local on Telebirr; a sandwich at 20:00 in cash
    $at('2026-11-21 05:30:00');
    $c = $f->send([$m->line($m->croissant, '3'), $m->line($m->water)]);
    $f->act('served', $f->lineOf($c, $m->croissant)['id'], $f->waiter)->assertOk();
    $f->act('served', $f->lineOf($c, $m->water)['id'], $f->waiter)->assertOk();
    $at('2026-11-21 05:40:00');
    Receipts::pay($this, $f, $c['id'], 'telebirr', '210.00', ['reference_no' => 'FT222'])->assertCreated();
    $at('2026-11-21 17:00:00');
    $d = $f->send([$m->line($m->sandwich)]);
    $made($d, $m->sandwich, $f->chef, '2026-11-21 17:01:00', '2026-11-21 17:09:00', '2026-11-21 17:10:00');
    $at('2026-11-21 17:12:00');
    Receipts::pay($this, $f, $d['id'], 'cash', '250.00', ['tendered_amount' => '250.00'])->assertCreated();

    $at('2026-11-22 03:00:00');
    foreach (['2026-11-20', '2026-11-21'] as $day) {
        app(Rebuild::class)->day($f->branch->id, $day, 'manual');
    }
    $at('2026-11-22 07:00:00'); // 10:00 local on Sunday the 22nd

    $this->range = ['from' => '2026-11-20', 'to' => '2026-11-21'];
    $this->start = '2026-11-19 21:00:00'; // the 20th's local midnight in UTC
    $this->end = '2026-11-21 21:00:00';   // the 22nd's
    $this->get = function (string $path, array $query = []): array {
        loginAs($this->f->admin);

        return $this->getJson('/api/v1/analytics/'.$path.'?'.http_build_query($query + $this->range))->assertOk()->json();
    };
    $this->local = fn (string $utc): CarbonImmutable => CarbonImmutable::parse($utc, 'UTC')->setTimezone('Africa/Addis_Ababa');
    $this->money = fn (iterable $values): string => (string) array_reduce(is_array($values) ? $values : iterator_to_array($values), fn (BigDecimal $sum, $v) => $sum->plus((string) $v), BigDecimal::zero())->toScale(2);
    $this->qty = fn (iterable $values): string => (string) array_reduce(is_array($values) ? $values : iterator_to_array($values), fn (BigDecimal $sum, $v) => $sum->plus((string) $v), BigDecimal::zero())->toScale(4);
    // the oracle's sources, straight from the operational tables
    $this->orders = DB::table('orders')->where('branch_id', $f->branch->id)->whereNotNull('settled_at')->whereNotIn('status', ['voided', 'cancelled'])
        ->where('settled_at', '>=', $this->start)->where('settled_at', '<', $this->end)->get();
    $this->lines = DB::table('order_lines')->whereIn('order_id', $this->orders->pluck('id'))->get();
    $this->payments = DB::table('payments')->where('branch_id', $f->branch->id)->where('status', '!=', 'voided')
        ->where('received_at', '>=', $this->start)->where('received_at', '<', $this->end)->get();
    $this->refunds = DB::table('payment_refunds')->where('branch_id', $f->branch->id)->where('refunded_at', '>=', $this->start)->where('refunded_at', '<', $this->end)->get();
});

it('makes the overview of the summed facts: revenue, refunds, orders, the average ticket, voids, order types, channels and top products', function () {
    $overview = ($this->get)('overview')['data'];
    $revenue = ($this->money)($this->orders->pluck('total'));
    $refunds = ($this->money)($this->refunds->pluck('amount'));
    $voided = DB::table('orders')->where('branch_id', $this->f->branch->id)->where('status', 'voided')->where('voided_at', '>=', $this->start)->where('voided_at', '<', $this->end)->get();

    expect($overview['revenue'])->toBe($revenue)->and($revenue)->toBe('1200.00')
        ->and($overview['orders_count'])->toBe($this->orders->count())
        ->and($overview['items_qty'])->toBe(($this->qty)($this->lines->pluck('qty')))
        ->and($overview['avg_ticket'])->toBe((string) BigDecimal::of($revenue)->dividedBy($this->orders->count(), 2, RoundingMode::HalfUp))
        ->and($overview['refund_total'])->toBe($refunds)
        ->and($overview['refund_count'])->toBe($this->refunds->count())
        ->and($overview['net_revenue'])->toBe((string) BigDecimal::of($revenue)->minus($refunds))
        ->and($overview['voided_count'])->toBe($voided->count())
        ->and($overview['voided_total'])->toBe(($this->money)($voided->pluck('total')))
        ->and($overview['by_type']['takeaway'])->toBe(($this->money)($this->orders->where('type', 'takeaway')->pluck('total')));

    $byChannel = [];
    foreach ($overview['channels'] as $row) {
        $byChannel[$row['channel']['id']] = $row['amount'];
    }
    $paid = [];
    foreach ($this->payments->groupBy('channel_id') as $channelId => $rows) {
        $paid[$channelId] = ($this->money)($rows->pluck('amount'));
    }
    ksort($byChannel);
    ksort($paid);
    expect($byChannel)->toBe($paid);

    $top = $overview['top_items'][0];
    $sandwiches = $this->lines->where('item_id', $this->f->menu->sandwich->id);
    expect($top['item']['id'])->toBe($this->f->menu->sandwich->id)
        ->and($top['revenue'])->toBe(($this->money)($sandwiches->pluck('line_total')))
        ->and($top['units_sold_qty'])->toBe(($this->qty)($sandwiches->pluck('qty')));
});

it('makes the day series and the hours of the summed facts', function () {
    $series = ($this->get)('sales')['data']['series'];
    $hours = ($this->get)('sales/hourly')['data']['hours'];

    foreach ($series as $point) {
        $mine = $this->orders->filter(fn ($o) => ($this->local)($o->settled_at)->toDateString() === $point['key']);
        expect($point['orders_count'])->toBe($mine->count(), $point['key'])
            ->and($point['revenue'])->toBe(($this->money)($mine->pluck('total')), $point['key']);
    }
    foreach ($hours as $hour) {
        $mine = $this->orders->filter(fn ($o) => (int) ($this->local)($o->settled_at)->format('G') === $hour['hour']);
        expect($hour['orders_count'])->toBe($mine->count(), (string) $hour['hour'])
            ->and($hour['revenue'])->toBe(($this->money)($mine->pluck('total')), (string) $hour['hour']);
    }
    expect(array_column($series, 'revenue'))->toBe(['740.00', '460.00']);
});

it('makes the channels of the summed payments and refunds', function () {
    $channels = ($this->get)('channels')['data']['channels'];

    expect($channels)->toHaveCount($this->payments->pluck('channel_id')->unique()->count());
    foreach ($channels as $row) {
        $mine = $this->payments->where('channel_id', $row['channel']['id']);
        $back = $this->refunds->where('channel_id', $row['channel']['id']);
        expect($row['receipts_count'])->toBe($mine->count())
            ->and($row['amount'])->toBe(($this->money)($mine->pluck('amount')))
            ->and($row['refund_amount'])->toBe(($this->money)($back->pluck('amount')))
            ->and($row['net_amount'])->toBe((string) BigDecimal::of($row['amount'])->minus($row['refund_amount']));
    }
});

it('makes the products of the summed lines, the cost of the in-house display-case product honestly incomplete', function () {
    $items = ($this->get)('items', ['kind' => 'product'])['data']['items'];
    $m = $this->f->menu;

    $sold = [];
    foreach ($this->lines->groupBy('item_id') as $itemId => $rows) {
        $sold[$itemId] = [($this->qty)($rows->pluck('qty')), ($this->money)($rows->pluck('line_total'))];
    }
    $answered = [];
    foreach ($items as $row) {
        if ($row['units_sold_qty'] !== '0.0000') {
            $answered[$row['item']['id']] = [$row['units_sold_qty'], $row['revenue']];
        }
    }
    ksort($sold);
    ksort($answered);
    expect($answered)->toBe($sold);

    $row = fn ($item): array => collect($items)->firstWhere('item.id', $item->id);
    expect($row($m->croissant))->toMatchArray(['units_sold_qty' => '4.0000', 'cogs_total' => '0.00', 'cogs_complete' => false, 'cogs_incomplete_days' => 2])
        ->and($row($m->sandwich))->toMatchArray(['units_sold_qty' => '3.0000', 'cogs_total' => '205.35', 'cogs_complete' => true]);

    $page = ($this->get)('items/'.$m->croissant->id)['data'];
    foreach ($page['series'] as $point) {
        $mine = $this->lines->where('item_id', $m->croissant->id)->filter(
            fn ($line) => ($this->local)((string) $this->orders->firstWhere('id', $line->order_id)->settled_at)->toDateString() === $point['day'],
        );
        expect($point['units_sold_qty'])->toBe(($this->qty)($mine->pluck('qty')), $point['day']);
    }
    expect($page['since_launch']['first_sale_day'])->toBe('2026-11-20');
});

it('makes the stations, the staff and the shifts of the summed lines, payments and shifts', function () {
    $f = $this->f;
    $m = $f->menu;
    $stations = collect(($this->get)('stations')['data']['stations'])->keyBy('station.id');
    $kitchenLines = DB::table('order_lines')->where('branch_id', $f->branch->id)->where('station_id', $m->kitchenStation->id)->get();
    $served = $kitchenLines->filter(fn ($l) => $l->served_at !== null && $l->served_at >= $this->start && $l->served_at < $this->end);
    $readied = $kitchenLines->filter(fn ($l) => $l->ready_at !== null && $l->accepted_at !== null && $l->ready_at >= $this->start && $l->ready_at < $this->end);
    $seconds = $readied->map(fn ($l) => CarbonImmutable::parse($l->ready_at, 'UTC')->getTimestamp() - CarbonImmutable::parse($l->accepted_at, 'UTC')->getTimestamp());

    expect($stations[$m->kitchenStation->id]['lines_served'])->toBe($served->count())
        ->and($stations[$m->kitchenStation->id]['prep_samples'])->toBe($readied->count())
        ->and($stations[$m->kitchenStation->id]['avg_prep_seconds'])->toBe(BigDecimal::of($seconds->sum())->dividedBy($seconds->count(), 0, RoundingMode::HalfUp)->toInt())
        ->and($stations[$m->kitchenStation->id]['avg_prep_seconds'])->toBe(540);

    $staff = collect(($this->get)('staff')['data']['users']);
    $cashier = $staff->first(fn ($r) => $r['user']['id'] === $f->cashier->id && $r['role_kind'] === 'cashier');
    $waiter = $staff->first(fn ($r) => $r['user']['id'] === $f->waiter->id && $r['role_kind'] === 'waiter');
    $taken = $this->payments->where('received_by_user_id', $f->cashier->id);
    $owned = $this->orders->where('owner_user_id', $f->waiter->id);
    expect($cashier['payments_count'])->toBe($taken->count())
        ->and($cashier['payments_amount'])->toBe(($this->money)($taken->pluck('amount')))
        ->and($waiter['orders_count'])->toBe($owned->count())
        ->and($waiter['revenue'])->toBe(($this->money)($owned->pluck('total')));

    foreach (($this->get)('shifts')['data']['days'] as $day) {
        $dayStart = CarbonImmutable::parse($day['day'].' 00:00:00', 'Africa/Addis_Ababa')->utc()->format('Y-m-d H:i:s');
        $dayEnd = CarbonImmutable::parse($day['day'].' 00:00:00', 'Africa/Addis_Ababa')->addDay()->utc()->format('Y-m-d H:i:s');
        $alive = DB::table('shift_instances')->where('branch_id', $f->branch->id)->whereNotNull('opened_at')->where('opened_at', '<', $dayEnd)
            ->where(fn ($q) => $q->whereNull('locked_at')->orWhere('locked_at', '>=', $dayStart))
            ->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>=', $dayStart))
            ->count();
        expect($day['shifts_count'])->toBe($alive, $day['day'])->and($alive)->toBeGreaterThanOrEqual(3);
    }
});
