<?php

declare(strict_types=1);

use App\Modules\Orders\Contracts\Orders;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Shared\Events\OrderPaid;
use App\Shared\Events\PaymentRecorded;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\OrderFloor;

/*
 * Contract: payments -> orders (manifest contracts["payments->orders"]; phase-1 plan sections 2 and 5, step 1.4).
 *
 * Written BEFORE any code exists in App\Modules\Payments: only Orders' public side (Orders\Contracts and the events it
 * reacts to) and the events of App\Shared. Each case is phrased from Payments' side: "when I, Payments, record a
 * payment, I ask Orders for the totals, then I dispatch PaymentRecorded inside my transaction; I rely on Orders to add
 * the amount, to settle the order and, once it is served, to make it paid, sell its display-case lines and close it".
 * Orders is set up through its own API (OrderFloor); the payment is simulated by dispatching the event exactly as
 * Payments will, inside a transaction. It runs in Orders' CI job (mod-orders) for the life of the project.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->totals = fn (int $id) => app(Orders::class)->totals($id);
    // what Payments::record does after its own checks: one transaction around the event (and, in the real thing, the row)
    $this->pay = function (int $orderId, ?string $amount = null, ?string $balanceAfter = null, int $paymentId = 1): void {
        withActor($this->floor->cashier->id);
        $balance = ($this->totals)($orderId)->balance;
        $amount ??= $balance;
        $balanceAfter ??= BigDecimal::of($balance)->minus($amount)->toScale(2)->__toString();
        DB::transaction(function () use ($orderId, $amount, $balanceAfter, $paymentId): void {
            event(new PaymentRecorded($this->floor->branch->id, $paymentId, $orderId, 'cash', $amount, $balanceAfter));
        });
    };
    $this->serveAll = function (array $order): void {
        $f = $this->floor;
        foreach ($order['lines'] as $line) {
            $station = $line['station']['code'] ?? null;
            if ($station === 'kitchen') {
                $f->act('ready', $line['id'], $f->chef)->assertOk();
            } elseif ($station === 'bar') {
                $f->act('ready', $line['id'], $f->barista)->assertOk();
            }
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }
    };
    $this->status = fn (int $id): string => Order::query()->findOrFail($id)->status->value;
    $this->timeline = fn (int $id): array => OrderEvent::query()->where('order_id', $id)->orderBy('id')->get()
        ->map(fn (OrderEvent $e): string => $e->event->value)->all();
    $this->paidEvents = [];
    Event::listen(OrderPaid::class, function (OrderPaid $e): void {
        $this->paidEvents[] = $e->payload();
    });
});

it('answers total, paid and balance as decimal strings', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich, '2'), $m->line($m->croissant)]);

    $totals = ($this->totals)($order['id']);

    expect($totals->total)->toBeString()->toBe($order['totals']['total'])
        ->and($totals->paid)->toBe('0.00')
        ->and($totals->balance)->toBe($totals->total)
        ->and($totals->currency)->toBe('ETB');
})->group('contract:payments->orders');

it('moves a served order to paid, sells its display-case lines and closes it when the balance reaches zero', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich), $m->line($m->croissant, '2'), $m->line($m->water)]);
    ($this->serveAll)($order);
    expect(($this->status)($order['id']))->toBe('served');
    $croissantRef = $f->refOf($f->lineOf($order, $m->croissant)['client_uuid']);
    $waterRef = $f->refOf($f->lineOf($order, $m->water)['client_uuid']);
    $onHandBefore = $f->level($m->croissant, $m->display)->onHand;

    ($this->pay)($order['id']);

    $row = Order::query()->findOrFail($order['id']);
    $total = $order['totals']['total'];
    expect($row->status->value)->toBe('closed')
        ->and($row->paid_total->amount())->toBe($total)
        ->and($row->settled_at)->not->toBeNull()
        ->and($row->paid_at)->not->toBeNull()
        ->and($row->closed_at)->not->toBeNull()
        ->and(($this->totals)($order['id'])->balance)->toBe('0.00')
        ->and(($this->totals)($order['id'])->paid)->toBe($total)
        // one sale row per display-case line, carrying the line's ref
        ->and($f->moved($croissantRef, 'sale', [$m->croissant], $m->display))->toBe([$m->croissant->id => '2.0000'])
        ->and($f->moved($waterRef, 'sale', [$m->water], $m->display))->toBe([$m->water->id => '1.0000'])
        ->and($f->outstanding($croissantRef))->toBe([])
        ->and($f->level($m->croissant, $m->display)->onHand->toString())->toBe($onHandBefore->minus('2')->toString())
        ->and($f->level($m->croissant, $m->display)->reserved->toString())->toBe('0.0000')
        ->and(array_slice(($this->timeline)($order['id']), -3))->toBe(['order.settled', 'order.paid', 'order.closed']);
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
    // OrderPaid is announced once, after the commit, with the order and its total
    expect($this->paidEvents)->toHaveCount(1)
        ->and($this->paidEvents[0]['order_id'])->toBe($order['id'])
        ->and($this->paidEvents[0]['total'])->toBe($total);
})->group('contract:payments->orders');

it('only settles an order that is not served yet, and pays and closes it when its last line is served', function () {
    $f = $this->floor;
    $m = $f->menu;
    // takeaway at the counter: paid before the food
    $order = $f->send([$m->line($m->sandwich), $m->line($m->croissant)]);
    $croissant = $f->lineOf($order, $m->croissant);
    $sandwich = $f->lineOf($order, $m->sandwich);
    $croissantRef = $f->refOf($croissant['client_uuid']);

    ($this->pay)($order['id']);

    $row = Order::query()->findOrFail($order['id']);
    expect($row->status->value)->toBe('sent')
        ->and($row->settled_at)->not->toBeNull()
        ->and($row->paid_at)->toBeNull()
        ->and($row->paid_total->amount())->toBe($order['totals']['total'])
        ->and(($this->totals)($order['id'])->balance)->toBe('0.00')
        ->and(($this->timeline)($order['id']))->toContain('order.settled')->not->toContain('order.paid')
        ->and($f->moved($croissantRef, 'sale', [$m->croissant], $m->display))->toBe([])
        ->and($this->paidEvents)->toBe([]);

    // the food comes: nothing is paid or closed until the last line is handed over
    $f->act('ready', $sandwich['id'], $f->chef)->assertOk();
    $f->act('served', $sandwich['id'], $f->waiter)->assertOk();
    expect(($this->status)($order['id']))->toBe('ready'); // the croissant is ready, not handed over yet
    $f->act('served', $croissant['id'], $f->waiter)->assertOk();

    $row->refresh();
    $timeline = ($this->timeline)($order['id']);
    expect($row->status->value)->toBe('closed')
        ->and($row->paid_at)->not->toBeNull()
        ->and($row->closed_at)->not->toBeNull()
        ->and($f->moved($croissantRef, 'sale', [$m->croissant], $m->display))->toBe([$m->croissant->id => '1.0000'])
        ->and(array_slice($timeline, -3))->toBe(['order.served', 'order.paid', 'order.closed'])
        ->and($this->paidEvents)->toHaveCount(1)
        ->and($this->paidEvents[0]['order_id'])->toBe($order['id']);
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('contract:payments->orders');

it('applies the payment inside the payment transaction: a rollback leaves the order and the ledger as they were', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich), $m->line($m->croissant)]);
    ($this->serveAll)($order);
    $croissantRef = $f->refOf($f->lineOf($order, $m->croissant)['client_uuid']);
    $events = count(($this->timeline)($order['id']));

    try {
        DB::transaction(function () use ($order): void {
            withActor($this->floor->cashier->id);
            event(new PaymentRecorded($this->floor->branch->id, 1, $order['id'], 'cash', ($this->totals)($order['id'])->balance, '0.00'));
            expect(($this->status)($order['id']))->toBe('closed'); // already applied, inside the transaction
            throw new RuntimeException('payment insert failed');
        });
    } catch (RuntimeException $e) {
        // rolled back with the payment; anything but our own failure (an unmet expectation) is a failure of the test
        expect($e->getMessage())->toBe('payment insert failed');
    }

    $row = Order::query()->findOrFail($order['id']);
    expect($row->status->value)->toBe('served')
        ->and($row->paid_total->amount())->toBe('0.00')
        ->and($row->settled_at)->toBeNull()
        ->and(count(($this->timeline)($order['id'])))->toBe($events)
        ->and($f->moved($croissantRef, 'sale', [$m->croissant], $m->display))->toBe([])
        ->and($f->outstanding($croissantRef))->not->toBe([])
        ->and($this->paidEvents)->toBe([]);
})->group('contract:payments->orders');

it('keeps the order unsettled when the amount is less than the balance', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich), $m->line($m->croissant)]);
    ($this->serveAll)($order);

    ($this->pay)($order['id'], '100.00');

    $row = Order::query()->findOrFail($order['id']);
    expect($row->status->value)->toBe('served')
        ->and($row->paid_total->amount())->toBe('100.00')
        ->and($row->settled_at)->toBeNull()
        ->and(($this->totals)($order['id'])->paid)->toBe('100.00')
        ->and(($this->totals)($order['id'])->balance)->not->toBe('0.00')
        ->and($this->paidEvents)->toBe([]);
})->group('contract:payments->orders');
