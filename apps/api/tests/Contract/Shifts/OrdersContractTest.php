<?php

declare(strict_types=1);

use App\Modules\Orders\Contracts\OpenLineView;
use App\Modules\Orders\Contracts\OpenOrderView;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Tests\Support\OrderFloor;

/*
 * Contract: shifts -> orders (manifest contracts["shifts->orders"]; phase-3 plan sections 1 and 2).
 *
 * Written BEFORE any code exists in App\Modules\Shifts (step 3.1a): only Orders' public side, its factories and the
 * OrderFloor the Orders tests use. Phrased from Shifts' side: "when I, Shifts, replay a station at a minute (step 3.6),
 * I ask Orders which orders were open there then and what each of their lines at the station looked like; I rely on it
 * reading the append-only timeline, never the current status". The capability is built (Orders::openAt, Phase 1 step
 * 1.3e) and has its own module test (OrdersOpenAtTest); this file pins it from the consumer's side, so these cases are
 * green on arrival. It runs in Orders' CI job (mod-orders) for the life of the project.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->t0 = CarbonImmutable::now()->startOfSecond();
    $this->at = fn (int $seconds): CarbonImmutable => $this->t0->addSeconds($seconds);
    $this->go = fn (int $seconds) => $this->travelTo(($this->at)($seconds));
    $this->open = fn ($station, int $seconds): array => app(Orders::class)->openAt($station->id, ($this->at)($seconds));
    $this->statuses = function (array $views): array {
        $out = [];
        foreach ($views as $view) {
            expect($view)->toBeInstanceOf(OpenOrderView::class);
            $out[$view->orderId] = $view->lines[0]->status;
        }

        return $out;
    };
});

it('reads the timeline, never the current status: an order closed after t is still open at t', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->go)(100);
    $order = $f->send([$m->line($m->sandwich, '2')]);
    $line = $f->lineOf($order, $m->sandwich)['id'];
    ($this->go)(160);
    $f->act('accept', $line, $f->chef)->assertOk();
    ($this->go)(220);
    $f->act('ready', $line, $f->chef)->assertOk();
    // the live rows say voided and closed now; the timeline still says what happened when
    OrderLine::query()->whereKey($line)->update(['status' => 'voided']);
    Order::query()->whereKey($order['id'])->update(['status' => 'closed', 'closed_at' => ($this->at)(221)]);
    ($this->go)(1000);

    $status = fn (int $s): array => ($this->statuses)(($this->open)($m->kitchenStation, $s));
    expect($status(99))->toBe([])
        ->and($status(100))->toBe([$order['id'] => 'queued'])
        ->and($status(160))->toBe([$order['id'] => 'accepted'])
        ->and($status(220))->toBe([$order['id'] => 'ready'])
        ->and($status(900))->toBe([$order['id'] => 'ready']);
})->group('contract:shifts->orders', 'contract');

it('keeps a paid but not yet closed order open, and drops it at its order.closed', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->go)(10);
    $order = $f->send([$m->line($m->sandwich)]);
    $event = fn (OrderEventType $type, int $seconds) => OrderEvent::factory()->create([
        'order_id' => $order['id'], 'event' => $type, 'created_at' => ($this->at)($seconds),
    ]);
    $event(OrderEventType::OrderPaid, 100);
    $event(OrderEventType::OrderClosed, 140);

    expect(($this->statuses)(($this->open)($m->kitchenStation, 100)))->toBe([$order['id'] => 'queued'])
        ->and(($this->statuses)(($this->open)($m->kitchenStation, 139)))->toBe([$order['id'] => 'queued'])
        ->and(($this->open)($m->kitchenStation, 140))->toBe([])
        ->and(($this->open)($m->kitchenStation, 5000))->toBe([]);
})->group('contract:shifts->orders', 'contract');

it('gives each line the status its last line event at or before t gave it, only for the station asked about', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->go)(10);
    $mixed = $f->send([$m->line($m->sandwich), $m->line($m->latte), $m->line($m->croissant)]);
    ($this->go)(70);
    $f->act('ready', $f->lineOf($mixed, $m->sandwich)['id'], $f->chef)->assertOk();
    ($this->go)(200);

    $kitchen = ($this->open)($m->kitchenStation, 80);
    $bar = ($this->open)($m->barStation, 80);
    $earlier = ($this->open)($m->kitchenStation, 60);

    expect($kitchen)->toHaveCount(1)
        ->and($kitchen[0]->lines)->toHaveCount(1)
        ->and($kitchen[0]->lines[0])->toBeInstanceOf(OpenLineView::class)
        ->and($kitchen[0]->lines[0]->itemId)->toBe($m->sandwich->id)
        ->and($kitchen[0]->lines[0]->status)->toBe('ready')
        ->and($bar[0]->lines)->toHaveCount(1)
        ->and($bar[0]->lines[0]->itemId)->toBe($m->latte->id)
        ->and($bar[0]->lines[0]->status)->toBe('queued')
        ->and($earlier[0]->lines[0]->status)->toBe('queued')
        ->and(($this->open)($m->tillStation, 80))->toBe([], 'a display-case line has no order at the till');
})->group('contract:shifts->orders', 'contract');

it('lists the oldest sent first, then by order id', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->go)(10);
    $first = $f->send([$m->line($m->sandwich)]);
    $second = $f->send([$m->line($m->sandwich)]);
    ($this->go)(20);
    $third = $f->send([$m->line($m->sandwich)]);
    // an order sent earlier than all of them, written last
    $early = Order::factory()->sent()->create(['branch_id' => $f->branch->id, 'order_no' => 900]);
    $earlyLine = OrderLine::factory()->atStation($m->kitchenStation)->create(['order_id' => $early->id, 'branch_id' => $f->branch->id]);
    OrderEvent::factory()->create(['order_id' => $early->id, 'event' => OrderEventType::OrderSent, 'created_at' => ($this->at)(5)]);
    OrderEvent::factory()->create(['order_id' => $early->id, 'order_line_id' => $earlyLine->id, 'station_id' => $m->kitchenStation->id, 'event' => OrderEventType::LineQueued, 'created_at' => ($this->at)(5)]);

    $ids = array_map(fn (OpenOrderView $v): int => $v->orderId, ($this->open)($m->kitchenStation, 30));

    expect($ids)->toBe([$early->id, $first['id'], $second['id'], $third['id']]);
})->group('contract:shifts->orders', 'contract');

it('never sees another branch\'s orders', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->go)(10);
    $mine = $f->send([$m->line($m->sandwich)]);
    $other = Branch::factory()->create();
    $theirs = Order::factory()->sent()->create(['branch_id' => $other->id]);
    $theirLine = OrderLine::factory()->atStation($m->kitchenStation)->create(['order_id' => $theirs->id, 'branch_id' => $other->id]);
    OrderEvent::factory()->create(['order_id' => $theirs->id, 'event' => OrderEventType::OrderSent, 'created_at' => ($this->at)(10)]);
    OrderEvent::factory()->create(['order_id' => $theirs->id, 'order_line_id' => $theirLine->id, 'station_id' => $m->kitchenStation->id, 'event' => OrderEventType::LineQueued, 'created_at' => ($this->at)(10)]);

    $ids = array_map(fn (OpenOrderView $v): int => $v->orderId, ($this->open)($m->kitchenStation, 20));

    expect($ids)->toBe([$mine['id']]);
})->group('contract:shifts->orders', 'contract');
