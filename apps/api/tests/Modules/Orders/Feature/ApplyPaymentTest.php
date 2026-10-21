<?php

declare(strict_types=1);

use App\Modules\Orders\Actions\VoidOrder;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Shared\Events\OrderPaid;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Live\Broadcasts\OrderPaidBroadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OrderFloor;

/*
 * Orders' side of PaymentRecorded (phase-1 plan sections 5 and 6, step 1.4; the contract payments->orders is in
 * tests/Contract/Payments): the ApplyPayment listener and Settlement. Orders never imports Payments; the payment is the
 * event, dispatched inside a transaction as Payments will.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->pay = function (int $orderId, string $amount, string $balanceAfter = '0.00'): void {
        withActor($this->floor->cashier->id);
        DB::transaction(function () use ($orderId, $amount, $balanceAfter): void {
            event(new PaymentRecorded($this->floor->branch->id, 1, $orderId, 'cash', $amount, $balanceAfter));
        });
    };
    $this->order = fn (int $id): Order => Order::query()->findOrFail($id);
    $this->timeline = fn (int $id): array => OrderEvent::query()->where('order_id', $id)->orderBy('id')->get()->map(fn (OrderEvent $e): array => [$e->event->value, $e->payload])->all();
});

it('settles a served order with the whole balance: paid and closed in one transaction, sold lines in the timeline', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->croissant), $m->line($m->water)]);
    $lines = array_column($order['lines'], 'id');
    foreach ($lines as $id) {
        $f->act('served', $id, $f->waiter)->assertOk();
    }
    $before = ($this->order)($order['id']);
    expect($before->status->value)->toBe('served');

    ($this->pay)($order['id'], $order['totals']['total']);

    $after = ($this->order)($order['id']);
    $timeline = ($this->timeline)($order['id']);
    expect($after->status->value)->toBe('closed')
        ->and($after->version)->toBeGreaterThan($before->version)
        ->and($after->settled_at)->not->toBeNull()
        ->and(array_column(array_slice($timeline, -3), 0))->toBe(['order.settled', 'order.paid', 'order.closed'])
        ->and(array_slice($timeline, -1)[0][1]['sold_line_ids'])->toBe($lines)
        ->and(array_slice($timeline, -1)[0][1]['movement_ids'])->toHaveCount(2);
})->group('module:orders', 'feature');

it('takes a part payment into paid_total without settling', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->croissant), $m->line($m->water)]);

    ($this->pay)($order['id'], '10.00', '999.00');

    $row = ($this->order)($order['id']);
    expect($row->paid_total->amount())->toBe('10.00')
        ->and($row->settled_at)->toBeNull()
        ->and($row->status->value)->toBe('ready')
        ->and(array_column(($this->timeline)($order['id']), 0))->not->toContain('order.settled');
})->group('module:orders', 'feature');

it('closes an order paid before the food when its last station line is served, with nothing to sell', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->latte)]);
    $latte = $f->lineOf($order, $m->latte);
    ($this->pay)($order['id'], $order['totals']['total']);
    expect(($this->order)($order['id'])->status->value)->toBe('sent');

    $f->act('ready', $latte['id'], $f->barista)->assertOk();
    expect(($this->order)($order['id'])->status->value)->toBe('ready');
    $f->act('served', $latte['id'], $f->waiter)->assertOk();

    $row = ($this->order)($order['id']);
    expect($row->status->value)->toBe('closed')
        ->and(array_slice(array_column(($this->timeline)($order['id']), 0), -3))->toBe(['order.served', 'order.paid', 'order.closed'])
        ->and(array_slice(($this->timeline)($order['id']), -1)[0][1]['sold_line_ids'])->toBe([]);
})->group('module:orders', 'feature');

it('refuses what Payments should have refused, and writes nothing: open, cancelled, voided, settled, overpay, zero', function () {
    $f = $this->floor;
    $m = $f->menu;
    $open = $f->send([$m->line($m->croissant)], null, ['send' => false]);
    $settled = $f->send([$m->line($m->croissant)]);
    ($this->pay)($settled['id'], $settled['totals']['total']);
    $sent = $f->send([$m->line($m->croissant)]);
    $events = DB::table('order_events')->count();

    $try = function (int $orderId, string $amount): string {
        try {
            ($this->pay)($orderId, $amount);
        } catch (LogicException $e) {
            return $e->getMessage();
        }

        return 'accepted';
    };

    expect($try($open['id'], '10.00'))->toContain('cannot take a payment in status open')
        ->and($try($settled['id'], '10.00'))->toContain('(settled)')
        ->and($try($sent['id'], '99999.00'))->toContain('does not fit the balance')
        ->and($try($sent['id'], '0.00'))->toContain('does not fit the balance')
        ->and(DB::table('order_events')->count())->toBe($events)
        ->and(($this->order)($sent['id'])->paid_total->amount())->toBe('0.00');
})->group('module:orders', 'feature');

it('keeps a settled order out of the void path: a paid order is a refund, not a void', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->latte)]);
    ($this->pay)($order['id'], $order['totals']['total']);

    app(VoidOrder::class)->assertVoidable(($this->order)($order['id']));
})->group('module:orders', 'feature')->throws(OrderError::class);

it('announces OrderPaid once, after the commit, with its inert companion on the branch orders channel', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->croissant)]);
    $f->act('served', $order['lines'][0]['id'], $f->waiter)->assertOk();
    $baseline = DB::connection()->transactionLevel();
    $seen = [];
    Event::listen(OrderPaid::class, function (OrderPaid $e) use (&$seen): void {
        $seen[] = [$e, DB::connection()->transactionLevel()];
    });
    $companions = [];
    Event::listen(OrderPaidBroadcast::class, function (OrderPaidBroadcast $b) use (&$companions): void {
        $companions[] = $b;
    });
    Queue::fake();

    ($this->pay)($order['id'], $order['totals']['total']);

    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toBe($baseline)
        ->and($seen[0][0]->payload())->toMatchArray(['order_id' => $order['id'], 'branch_id' => $f->branch->id, 'total' => $order['totals']['total']])
        ->and($seen[0][0]->payload()['paid_at'])->toBe(($this->order)($order['id'])->paid_at->toIso8601ZuluString())
        ->and($companions)->toHaveCount(1)
        ->and(array_map(fn ($c): string => $c->name, $companions[0]->broadcastOn()))->toBe(["private-branch.{$f->branch->id}.orders"])
        ->and($companions[0]->broadcastAs())->toBe('order_paid')
        ->and($companions[0]->broadcastWhen())->toBeFalse();
    assertNothingQueuedButStockEvaluation();
})->group('module:orders', 'feature');
