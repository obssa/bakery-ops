<?php

declare(strict_types=1);

use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Events\PaymentVoided;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrderFloor;

pest()->group('module:orders', 'feature');

/*
 * Orders' side of PaymentVoided (phase-4 plan sections 5 and 10.4, step 4.3c; the contract case is in tests/Contract/Payments/OrdersContractTest.php):
 * the ReversePayment listener runs synchronously inside the voiding transaction, under the order's row lock. It takes the amount back out of
 * paid_total, clears settled_at of an order that was settled but not served yet, bumps the version for the live feeds and writes payment.voided on
 * the timeline (who asked, whose PIN approved it). Nothing was sold yet, so no stock moves. An order that is paid or closed is refused with
 * payments.void.order_closed (it is refunded instead); an amount larger than what the order holds is a bug of the caller and rolls back. Orders
 * never imports Payments: the void is the event, dispatched inside a transaction as Payments does.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->pay = function (int $orderId, string $amount, int $paymentId = 1): void {
        withActor($this->floor->cashier->id);
        DB::transaction(function () use ($orderId, $amount, $paymentId): void {
            event(new PaymentRecorded($this->floor->branch->id, $paymentId, $orderId, 'cash', $amount, '0.00'));
        });
    };
    $this->voidPayment = function (int $orderId, string $amount, int $paymentId = 1): void {
        withActor($this->floor->cashier->id);
        DB::transaction(function () use ($orderId, $amount, $paymentId): void {
            event(new PaymentVoided($this->floor->branch->id, $paymentId, $orderId, 'cash', $amount, 'Keyed on the wrong bill', $this->floor->cashier->id, $this->floor->manager->id));
        });
    };
    $this->order = fn (int $id): Order => Order::query()->findOrFail($id);
});

it('takes a part payment back out of paid_total and writes payment.voided naming both people', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->croissant), $m->line($m->water)]);
    ($this->pay)($order['id'], '10.00', 7);
    $before = ($this->order)($order['id']);
    $movements = DB::table('stock_movements')->count();

    ($this->voidPayment)($order['id'], '10.00', 7);

    $after = ($this->order)($order['id']);
    expect($after->paid_total->amount())->toBe('0.00')
        ->and($after->status->value)->toBe($before->status->value)
        ->and($after->version)->toBe($before->version + 1)
        ->and(DB::table('stock_movements')->count())->toBe($movements, 'nothing was sold, nothing moves');
    $event = OrderEvent::query()->where('order_id', $order['id'])->where('event', 'payment.voided')->sole();
    expect($event->user_id)->toBe($f->cashier->id)
        ->and($event->approved_by_user_id)->toBe($f->manager->id)
        ->and($event->order_line_id)->toBeNull()
        ->and($event->owner_user_id)->toBe($f->waiter->id)
        ->and($event->payload)->toBe([
            'payment_id' => 7,
            'channel_code' => 'cash',
            'amount' => '10.00',
            'reason' => 'Keyed on the wrong bill',
            'by_user_id' => $f->cashier->id,
            'approved_by_user_id' => $f->manager->id,
        ]);
});

it('unsettles an order that was paid in full before it was served', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($f->menu->latte)]);
    ($this->pay)($order['id'], $order['totals']['total']);
    expect(($this->order)($order['id'])->settled_at)->not->toBeNull();

    ($this->voidPayment)($order['id'], $order['totals']['total']);

    $row = ($this->order)($order['id']);
    expect($row->settled_at)->toBeNull()
        ->and($row->paid_total->amount())->toBe('0.00')
        ->and($row->status->value)->toBe('sent');
});

it('takes the payment of a voided order back out too: the order stays voided', function () {
    $f = $this->floor;
    $order = Order::factory()->sent(31)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->waiter->id, 'status' => 'voided', 'paid_total' => '15.00']);

    ($this->voidPayment)($order->id, '15.00');

    $row = ($this->order)($order->id);
    expect($row->status->value)->toBe('voided')->and($row->paid_total->amount())->toBe('0.00');
});

it('refuses a paid or closed order with payments.void.order_closed and writes nothing', function () {
    $f = $this->floor;
    foreach (['paid' => 32, 'closed' => 33] as $status => $no) {
        $order = Order::factory()->sent($no)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->waiter->id, 'status' => $status, 'paid_total' => '20.00', 'total' => '20.00']);
        $events = DB::table('order_events')->count();
        try {
            ($this->voidPayment)($order->id, '20.00');
            $this->fail("a {$status} order took a void");
        } catch (OrderError $e) {
            expect($e->messageKey)->toBe('payments.void.order_closed')
                ->and($e->status)->toBe(409)
                ->and($e->extra)->toBe(['status' => $status]);
        }
        expect(($this->order)($order->id)->paid_total->amount())->toBe('20.00')
            ->and(DB::table('order_events')->count())->toBe($events);
    }
});

it('treats a void of more than the order holds as a bug of the caller and rolls back', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($f->menu->water)]);
    ($this->pay)($order['id'], '5.00');

    expect(fn () => ($this->voidPayment)($order['id'], '6.00'))->toThrow(LogicException::class, 'more than');
    expect(($this->order)($order['id'])->paid_total->amount())->toBe('5.00')
        ->and(OrderEvent::query()->where('order_id', $order['id'])->where('event', 'payment.voided')->count())->toBe(0);
});
