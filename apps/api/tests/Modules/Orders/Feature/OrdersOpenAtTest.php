<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
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
 * The provided capability Orders::openAt(stationId, at) for the shift replay of Phase 3 (manifest orders.provides;
 * docs/phases/phase-1-plan.md section 6, step 1.3e). The orders that had a line at the station and were open at $at:
 * sent at or before $at (order.sent) and not closed, cancelled or voided at or before $at (order.closed,
 * order.cancelled, order.voided), reconstructed from the append-only order_events timeline, never from the current
 * status. Each result carries the lines of that station only, each with its status as of $at (the last line.* event
 * at or before $at). Served lines stay in an order that is still open: the order is open until it is closed.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->t0 = CarbonImmutable::now()->startOfSecond();
    $this->at = fn (int $seconds): CarbonImmutable => $this->t0->addSeconds($seconds);
    $this->go = fn (int $seconds) => $this->travelTo(($this->at)($seconds));
    $this->open = fn ($station, int $seconds): array => app(Orders::class)->openAt($station->id, ($this->at)($seconds));
});

/** @return array<int, string> order id => the status of its first line, for the open orders of a result */
function openStatuses(array $views): array
{
    $out = [];
    foreach ($views as $view) {
        expect($view)->toBeInstanceOf(OpenOrderView::class);
        $out[$view->orderId] = $view->lines[0]->status;
    }

    return $out;
}

it('reconstructs an order across its life: absent before the send, present from it, each line with its status as of then', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->go)(100);
    $order = $f->send([$m->line($m->sandwich, '2')]);
    $line = $f->lineOf($order, $m->sandwich)['id'];
    ($this->go)(160);
    $f->act('accept', $line, $f->chef)->assertOk();
    ($this->go)(220);
    $f->act('ready', $line, $f->chef)->assertOk();
    ($this->go)(280);
    $f->act('served', $line, $f->waiter)->assertOk();
    ($this->go)(1000);

    $status = fn (int $s): array => openStatuses(($this->open)($m->kitchenStation, $s));
    expect($status(99))->toBe([])
        ->and($status(100))->toBe([$order['id'] => 'queued'])
        ->and($status(159))->toBe([$order['id'] => 'queued'])
        ->and($status(160))->toBe([$order['id'] => 'accepted'])
        ->and($status(219))->toBe([$order['id'] => 'accepted'])
        ->and($status(220))->toBe([$order['id'] => 'ready'])
        ->and($status(280))->toBe([$order['id'] => 'served'])
        ->and($status(900))->toBe([$order['id'] => 'served']);

    $view = ($this->open)($m->kitchenStation, 170)[0];
    expect($view->orderNo)->toBe($order['order_no'])
        ->and($view->type)->toBe('takeaway')
        ->and($view->tableCode)->toBeNull()
        ->and($view->ownerUserId)->toBe($f->waiter->id)
        ->and($view->sentAt->equalTo(($this->at)(100)))->toBeTrue()
        ->and($view->lines)->toHaveCount(1)
        ->and($view->lines[0])->toBeInstanceOf(OpenLineView::class)
        ->and($view->lines[0]->lineId)->toBe($line)
        ->and($view->lines[0]->itemId)->toBe($m->sandwich->id)
        ->and($view->lines[0]->qty)->toBe('2.0000')
        ->and($view->lines[0]->status)->toBe('accepted');
})->group('module:orders', 'feature');

it('drops an order when it is voided, from that moment on', function () {
    $f = $this->floor;
    $m = $f->menu;
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    ($this->go)(10);
    $order = $f->send([$m->line($m->sandwich), $m->line($m->latte)]);
    ($this->go)(300);
    loginAs($f->cashier);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'Left', 'pin' => '246802'])->assertOk();
    ($this->go)(600);

    expect(openStatuses(($this->open)($m->kitchenStation, 299)))->toBe([$order['id'] => 'queued'])
        ->and(openStatuses(($this->open)($m->barStation, 299)))->toBe([$order['id'] => 'queued'])
        ->and(($this->open)($m->kitchenStation, 300))->toBe([])
        ->and(($this->open)($m->barStation, 450))->toBe([]);
})->group('module:orders', 'feature');

it('drops an order when it is cancelled because every line was rejected', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->go)(10);
    $order = $f->send([$m->line($m->sandwich)]);
    ($this->go)(70);
    $f->act('reject', $f->lineOf($order, $m->sandwich)['id'], $f->chef, ['reason' => 'equipment_down'])->assertOk();
    ($this->go)(200);

    expect(openStatuses(($this->open)($m->kitchenStation, 69)))->toBe([$order['id'] => 'queued'])
        ->and(($this->open)($m->kitchenStation, 70))->toBe([])
        ->and(($this->open)($m->kitchenStation, 150))->toBe([]);
})->group('module:orders', 'feature');

it('drops an order when it is closed, and keeps a paid but not yet closed order', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->go)(10);
    $order = $f->send([$m->line($m->sandwich)]);
    $event = fn (OrderEventType $type, int $seconds) => OrderEvent::factory()->create([
        'order_id' => $order['id'], 'event' => $type, 'created_at' => ($this->at)($seconds),
    ]);
    $event(OrderEventType::OrderPaid, 100);
    $event(OrderEventType::OrderClosed, 140);

    expect(openStatuses(($this->open)($m->kitchenStation, 100)))->toBe([$order['id'] => 'queued'])
        ->and(openStatuses(($this->open)($m->kitchenStation, 139)))->toBe([$order['id'] => 'queued'])
        ->and(($this->open)($m->kitchenStation, 140))->toBe([])
        ->and(($this->open)($m->kitchenStation, 5000))->toBe([]);
})->group('module:orders', 'feature');

it('shows only the lines of the station asked, and no order that has none there', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->go)(10);
    $mixed = $f->send([$m->line($m->sandwich), $m->line($m->latte), $m->line($m->croissant)]);
    $kitchenOnly = $f->send([$m->line($m->sandwich)]);
    $displayOnly = $f->send([$m->line($m->croissant)]);

    $kitchen = ($this->open)($m->kitchenStation, 20);
    $bar = ($this->open)($m->barStation, 20);
    $till = ($this->open)($m->tillStation, 20);

    expect(array_map(fn (OpenOrderView $v): int => $v->orderId, $kitchen))->toBe([$mixed['id'], $kitchenOnly['id']])
        ->and(array_map(fn (OpenLineView $l): int => $l->itemId, $kitchen[0]->lines))->toBe([$m->sandwich->id])
        ->and(array_map(fn (OpenOrderView $v): int => $v->orderId, $bar))->toBe([$mixed['id']])
        ->and(array_map(fn (OpenLineView $l): int => $l->itemId, $bar[0]->lines))->toBe([$m->latte->id])
        ->and($till)->toBe([])
        ->and($displayOnly['id'])->not->toBeIn(array_map(fn (OpenOrderView $v): int => $v->orderId, [...$kitchen, ...$bar]));
})->group('module:orders', 'feature');

it('reads the timeline, not the current status: a row edited since changes nothing about the past', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->go)(10);
    $order = $f->send([$m->line($m->sandwich)]);
    $line = $f->lineOf($order, $m->sandwich)['id'];
    ($this->go)(50);
    $f->act('ready', $line, $f->chef)->assertOk();
    // the live rows say voided and closed now; the timeline still says what happened when
    OrderLine::query()->whereKey($line)->update(['status' => 'voided']);
    Order::query()->whereKey($order['id'])->update(['status' => 'closed', 'closed_at' => ($this->at)(51)]);

    expect(openStatuses(($this->open)($m->kitchenStation, 30)))->toBe([$order['id'] => 'queued'])
        ->and(openStatuses(($this->open)($m->kitchenStation, 60)))->toBe([$order['id'] => 'ready']);
})->group('module:orders', 'feature');

it('keeps the branches apart', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->go)(10);
    $f->send([$m->line($m->sandwich)]);
    $other = Branch::factory()->create();
    $theirs = Order::factory()->sent()->create(['branch_id' => $other->id]);
    $theirLine = OrderLine::factory()->atStation($m->kitchenStation)->create(['order_id' => $theirs->id, 'branch_id' => $other->id]);
    OrderEvent::factory()->create(['order_id' => $theirs->id, 'event' => OrderEventType::OrderSent, 'created_at' => ($this->at)(10)]);
    OrderEvent::factory()->create(['order_id' => $theirs->id, 'order_line_id' => $theirLine->id, 'station_id' => $m->kitchenStation->id, 'event' => OrderEventType::LineQueued, 'created_at' => ($this->at)(10)]);

    $ids = array_map(fn (OpenOrderView $v): int => $v->orderId, ($this->open)($m->kitchenStation, 20));

    expect($ids)->toHaveCount(1)->and($ids)->not->toContain($theirs->id);
})->group('module:orders', 'feature');
