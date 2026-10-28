<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\ItemStations;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Contracts\OrderRefundView;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Shared\Events\OrderRefunded;
use App\Shared\Events\PaymentRecorded;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

pest()->group('module:orders', 'feature');

/*
 * Orders' side of a refund (phase-4 plan sections 4.4 and 10.4, step 4.1b; Ramin's answer to R-C, 2026-10-28): Orders::refund on a closed
 * order. Each refunded line takes its disposition from its product's Catalog settings at that moment (restock: a reversing `return` per
 * movement of the product itself, through Inventory's Reversals; waste: nothing), the money back is the lines' line_total (the order's total
 * when every line goes back at once), and the order stays closed with refunded_total, refunded_at and the timeline. The money side
 * (payment_refunds, the endpoint POST /orders/{order}/refund) is step 4.3d; here the payment is the event, as Payments dispatches it, and
 * the refund is called as Payments will call it, after the manager's PIN. The three shift rules are on (the floor's stations have shifts).
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->orders = app(Orders::class);
    $this->pay = function (int $orderId): void {
        withActor($this->floor->cashier->id);
        $balance = $this->orders->totals($orderId)->balance;
        DB::transaction(function () use ($orderId, $balance): void {
            event(new PaymentRecorded($this->floor->branch->id, 1, $orderId, 'cash', $balance, '0.00'));
        });
    };
    $this->serve = function (array $order): void {
        $f = $this->floor;
        foreach ($order['lines'] as $line) {
            $code = $line['station']['code'] ?? null;
            if ($code === 'kitchen') {
                $f->act('ready', $line['id'], $f->chef)->assertOk();
            } elseif ($code === 'bar') {
                $f->act('ready', $line['id'], $f->barista)->assertOk();
            }
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }
    };
    // a closed order of the given lines (OrdersMenu::line)
    $this->closed = function (array $lines): array {
        $order = $this->floor->send($lines);
        ($this->serve)($order);
        ($this->pay)($order['id']);
        expect(Order::query()->findOrFail($order['id'])->status->value)->toBe('closed');

        return $order;
    };
    $this->refund = function (int $orderId, array $lines = [], ?string $uuid = null, ?int $approverId = null, string $reason = 'Customer returned it'): OrderRefundView {
        withActor($this->floor->cashier->id);
        $approver = $approverId === null ? $this->floor->manager : User::query()->findOrFail($approverId);

        return $this->orders->refund($orderId, $reason, $approver, $lines, $uuid ?? (string) Str::uuid());
    };
    $this->refusal = function (Closure $call): ?OrderError {
        try {
            $call();
        } catch (OrderError $e) {
            return $e;
        }

        return null;
    };
    $this->settings = function (Item $item, array $settings): void {
        DB::table('items')->where('id', $item->id)->update($settings);
    };
    $this->events = fn (int $orderId): array => OrderEvent::query()->where('order_id', $orderId)->orderBy('id')->get()
        ->map(fn (OrderEvent $e): array => ['event' => $e->event->value, 'line' => $e->order_line_id, 'approved_by' => $e->approved_by_user_id, 'payload' => $e->payload])->all();
    $this->written = fn (): array => [
        'refunds' => DB::table('order_refunds')->count(), 'lines' => DB::table('order_refund_lines')->count(),
        'movements' => DB::table('stock_movements')->count(), 'reversals' => DB::table('stock_movement_reversals')->count(),
        'events' => DB::table('order_events')->count(),
    ];
});

it('refunds the whole order at once: the total comes back, every line is refunded, the order stays closed and shows its refund', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->sandwich), $m->line($m->croissant, '2'), $m->line($m->water, '3')]);
    $before = Order::query()->findOrFail($order['id']);
    $refunded = [];
    Event::listen(OrderRefunded::class, function (OrderRefunded $e) use (&$refunded): void {
        $refunded[] = $e->payload();
    });

    $refund = ($this->refund)($order['id']);

    $row = Order::query()->findOrFail($order['id']);
    $croissant = $f->lineOf($order, $m->croissant)['id'];
    $sandwich = $f->lineOf($order, $m->sandwich)['id'];
    $water = $f->lineOf($order, $m->water)['id'];
    expect($refund->amount)->toBe($order['totals']['total'])
        ->and($refund->isFull)->toBeTrue()
        ->and($refund->lineIds())->toBe([$sandwich, $croissant, $water])
        ->and(array_map(fn ($l): string => $l->disposition, $refund->lines))->toBe(['waste', 'restock', 'restock'])
        ->and($row->status->value)->toBe('closed')
        ->and($row->refunded_total->amount())->toBe($order['totals']['total'])
        ->and($row->refunded_total->amount())->toBe($row->paid_total->amount())
        ->and($row->refunded_at)->not->toBeNull()
        ->and($row->version)->toBeGreaterThan($before->version)
        ->and(DB::table('order_lines')->where('order_id', $order['id'])->whereNull('refunded_at')->count())->toBe(0)
        ->and($refunded)->toHaveCount(1)
        ->and($refunded[0])->toMatchArray([
            'order_id' => $order['id'], 'refund_id' => $refund->id, 'amount' => $order['totals']['total'], 'is_full' => true,
            'line_ids' => [$sandwich, $croissant, $water], 'movement_ids' => $refund->movementIds(), 'by_user_id' => $f->cashier->id,
            'approved_by_user_id' => $f->manager->id, 'reason' => 'Customer returned it',
        ]);

    // the order record shows the refund (GET /orders/{order})
    loginAs($f->manager);
    $shown = $this->getJson("/api/v1/orders/{$order['id']}")->assertOk();
    expect($shown->json('status'))->toBe('closed')
        ->and($shown->json('refunded_total'))->toBe($order['totals']['total'])
        ->and($shown->json('refunds'))->toHaveCount(1)
        ->and($shown->json('refunds.0'))->toMatchArray(['id' => $refund->id, 'amount' => $order['totals']['total'], 'is_full' => true, 'reason' => 'Customer returned it'])
        ->and($shown->json('refunds.0.requested_by'))->toBe($f->cashier->id)
        ->and($shown->json('refunds.0.approved_by'))->toBe($f->manager->id)
        ->and(collect($shown->json('refunds.0.lines'))->pluck('disposition', 'order_line_id')->all())->toBe([$sandwich => 'waste', $croissant => 'restock', $water => 'restock'])
        ->and(collect($shown->json('lines'))->pluck('refunded_at')->filter()->count())->toBe(3)
        ->and(collect($shown->json('events'))->pluck('event')->all())->toContain('line.refunded', 'order.refunded');
});

it('writes line.refunded per line and order.refunded once, naming who asked and whose PIN approved it, and stamps the refund row', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant), $m->line($m->water)]);
    $croissant = $f->lineOf($order, $m->croissant);
    $known = count(($this->events)($order['id']));

    $refund = ($this->refund)($order['id'], [$croissant['id']], null, null, '  Stale, customer brought it back  ');

    $new = array_slice(($this->events)($order['id']), $known);
    expect(array_column($new, 'event'))->toBe(['line.refunded', 'order.refunded'])
        ->and($new[0]['line'])->toBe($croissant['id'])
        ->and($new[0]['approved_by'])->toBe($f->manager->id)
        ->and($new[0]['payload'])->toBe([
            'refund_id' => $refund->id, 'qty' => '1.0000', 'amount' => '60.00', 'disposition' => 'restock', 'restock' => true,
            'movement_ids' => $refund->movementIds(),
        ])
        ->and($new[1]['line'])->toBeNull()
        ->and($new[1]['approved_by'])->toBe($f->manager->id)
        ->and($new[1]['payload'])->toBe([
            'refund_id' => $refund->id, 'amount' => '60.00', 'is_full' => false, 'reason' => 'Stale, customer brought it back',
            'line_ids' => [$croissant['id']], 'by_user_id' => $f->cashier->id, 'approved_by_user_id' => $f->manager->id,
        ]);
    $row = DB::table('order_refunds')->where('id', $refund->id)->first();
    expect((int) $row->order_id)->toBe($order['id'])
        ->and((string) $row->amount)->toBe('60.00')
        ->and((bool) $row->is_full)->toBeFalse()
        ->and($row->reason)->toBe('Stale, customer brought it back')
        ->and((int) $row->requested_by_user_id)->toBe($f->cashier->id)
        ->and((int) $row->approved_by_user_id)->toBe($f->manager->id)
        ->and((int) $row->branch_id)->toBe($f->branch->id)
        ->and(DB::table('order_refund_lines')->where('order_refund_id', $refund->id)->get(['order_line_id', 'qty', 'line_amount', 'disposition'])->map(fn ($r): array => (array) $r)->all())
        ->toBe([['order_line_id' => $croissant['id'], 'qty' => '1.0000', 'line_amount' => '60.00', 'disposition' => 'restock']]);
});

it('answers a replay of the same client_uuid with the refund it made, writing nothing, and refuses the uuid for another order', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant), $m->line($m->water)]);
    $other = ($this->closed)([$m->line($m->water)]);
    $uuid = (string) Str::uuid();
    $first = ($this->refund)($order['id'], [$f->lineOf($order, $m->croissant)['id']], $uuid);
    $written = ($this->written)();
    $announced = 0;
    Event::listen(OrderRefunded::class, function () use (&$announced): void {
        $announced++;
    });

    $again = ($this->refund)($order['id'], [$f->lineOf($order, $m->croissant)['id']], $uuid);

    expect($again->id)->toBe($first->id)
        ->and($again->amount)->toBe($first->amount)
        ->and($again->movementIds())->toBe($first->movementIds())
        ->and(($this->written)())->toBe($written)
        ->and($announced)->toBe(0);
    $reused = ($this->refusal)(fn () => ($this->refund)($other['id'], [], $uuid));
    expect($reused?->messageKey)->toBe('orders.refund.client_uuid_reused')
        ->and($reused?->status)->toBe(409)
        ->and(($this->written)())->toBe($written);
});

it('refunds within the window of payments.refund_window_hours after the order closed, and refuses after it', function () {
    $f = $this->floor;
    $m = $f->menu;
    $early = ($this->closed)([$m->line($m->water)]);
    $late = ($this->closed)([$m->line($m->water)]);
    expect(config('payments.refund_window_hours'))->toBe(72);

    $this->travelTo(Order::query()->findOrFail($early['id'])->closed_at->addHours(72));
    ($this->refund)($early['id']);

    $this->travelTo(Order::query()->findOrFail($late['id'])->closed_at->addHours(72)->addSecond());
    $written = ($this->written)();
    $closed = ($this->refusal)(fn () => ($this->refund)($late['id']));
    expect($closed?->messageKey)->toBe('orders.refund.window_closed')
        ->and($closed?->status)->toBe(409)
        ->and($closed?->extra)->toBe(['hours' => 72])
        ->and(($this->written)())->toBe($written);

    // the number is plain config (R-O): an admin raises it in the server .env
    config(['payments.refund_window_hours' => 96]);
    ($this->refund)($late['id']);
});

it('refuses a partial refund of an order with a discount or tax, and refunds its whole total (R-D)', function () {
    $f = $this->floor;
    $m = $f->menu;
    foreach (['discount' => ['discount' => '10.00', 'total' => '80.00'], 'tax' => ['tax' => '13.50', 'total' => '103.50']] as $what => $money) {
        $order = $f->send([$m->line($m->croissant), $m->line($m->water)]);
        ($this->serve)($order);
        // the pad has no discount or tax yet (every line action totals the order again): the served order's row carries one, then it is paid
        DB::table('orders')->where('id', $order['id'])->update($money);
        ($this->pay)($order['id']);
        $croissant = $f->lineOf($order, $m->croissant)['id'];
        $written = ($this->written)();

        $partial = ($this->refusal)(fn () => ($this->refund)($order['id'], [$croissant]));
        expect($partial?->messageKey)->toBe('orders.refund.partial_not_allowed', $what)
            ->and($partial?->status)->toBe(422)
            ->and(($this->written)())->toBe($written);

        // a whole-order refund that would leave a non-refundable line behind is partial too
        ($this->settings)($m->water, ['refundable' => false]);
        expect(($this->refusal)(fn () => ($this->refund)($order['id']))?->messageKey)->toBe('orders.refund.partial_not_allowed', "{$what}: one line not refundable");
        ($this->settings)($m->water, ['refundable' => true]);

        // naming every line is the whole order
        $whole = ($this->refund)($order['id'], [$croissant, $f->lineOf($order, $m->water)['id']]);
        expect($whole->amount)->toBe($money['total'], $what)
            ->and($whole->isFull)->toBeTrue()
            ->and(Order::query()->findOrFail($order['id'])->refunded_total->amount())->toBe($money['total']);
    }
});

it('never refunds more than the order took in: refunded_total stays at or below paid_total', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant), $m->line($m->water)]);
    ($this->refund)($order['id'], [$f->lineOf($order, $m->croissant)['id']]);
    // a row whose money does not add up (paid_total below what its lines cost): the refund refuses rather than go above it
    DB::table('orders')->where('id', $order['id'])->update(['paid_total' => '70.00']);
    $written = ($this->written)();

    $over = ($this->refusal)(fn () => ($this->refund)($order['id'], [$f->lineOf($order, $m->water)['id']]));

    expect($over?->messageKey)->toBe('orders.refund.exceeds_paid')
        ->and($over?->status)->toBe(409)
        ->and($over?->extra)->toBe(['paid_total' => '70.00', 'refunded_total' => '60.00', 'amount' => '30.00'])
        ->and(($this->written)())->toBe($written);
    foreach (Order::query()->get() as $row) {
        expect(BigDecimal::of($row->refunded_total->amount())->isLessThanOrEqualTo($row->paid_total->amount()))->toBeTrue("order {$row->id}");
    }
});

it('refunds each line once, and only the charged lines of this order', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich), $m->line($m->latte), $m->line($m->water)]);
    $latte = $f->lineOf($order, $m->latte)['id'];
    $f->act('reject', $latte, $f->barista, ['reason' => 'ingredient_spoiled'])->assertOk();
    ($this->serve)(['lines' => [$f->lineOf($order, $m->sandwich), $f->lineOf($order, $m->water)]]);
    ($this->pay)($order['id']);
    $other = ($this->closed)([$m->line($m->water)]);
    $written = ($this->written)();

    foreach (['rejected (not charged)' => $latte, 'of another order' => $other['lines'][0]['id'], 'unknown' => 987654] as $what => $lineId) {
        $invalid = ($this->refusal)(fn () => ($this->refund)($order['id'], [$lineId]));
        expect($invalid?->messageKey)->toBe('orders.refund.line_invalid', $what)
            ->and($invalid?->status)->toBe(422)
            ->and($invalid?->extra)->toBe(['line_id' => $lineId]);
    }
    expect(($this->written)())->toBe($written);

    // the whole order leaves the rejected latte out; a named line twice is one line
    $water = $f->lineOf($order, $m->water)['id'];
    $refund = ($this->refund)($order['id'], [$water, $water]);
    expect($refund->lineIds())->toBe([$water]);
    $again = ($this->refusal)(fn () => ($this->refund)($order['id'], [$water, $f->lineOf($order, $m->sandwich)['id']]));
    expect($again?->messageKey)->toBe('orders.refund.line_refunded')
        ->and($again?->extra)->toBe(['line_ids' => [$water]]);
    // the database refuses a second refund line for the same order line whatever the code does
    expect(fn () => DB::table('order_refund_lines')->insert([
        'branch_id' => $f->branch->id, 'order_refund_id' => $refund->id, 'order_line_id' => $water, 'qty' => '1.0000',
        'line_amount' => '30.00', 'disposition' => 'waste', 'movement_ids' => '[]', 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('puts a stocked product made at a station back where the station took it from, and keeps the ledger oracle', function () {
    $f = $this->floor;
    $m = $f->menu;
    // the cake is sold from stock, but its order lines go to the kitchen, which keeps the cakes: its ready consumed the cake itself there
    app(ItemStations::class)->replace($m->cake, [$m->kitchenStation->id]);
    stockIn($m->cake, $m->kitchen, '3');
    $order = ($this->closed)([$m->line($m->cake), $m->line($m->croissant)]);
    $cakeLine = $f->lineOf($order, $m->cake);
    $consumed = DB::table('stock_movements')->where('reservation_ref', $f->refOf($cakeLine['client_uuid']))->where('reason', 'production_consume')->sole();

    $refund = ($this->refund)($order['id']);

    $returns = DB::table('stock_movements')->whereIn('id', $refund->movementIds())->orderBy('id')->get();
    expect($returns)->toHaveCount(2)
        ->and((int) $returns[0]->ref_id)->toBe((int) $consumed->id)
        ->and((int) $returns[0]->to_location_id)->toBe($m->kitchen->id)
        ->and((string) $returns[0]->qty)->toBe('1.0000')
        ->and(stockRow($m->cake, $m->kitchen))->on_hand->toBe('3.0000')
        ->and(stockRow($m->croissant, $m->display))->on_hand->toBe('5.0000');
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
});

it('writes nothing to stock for a product set to go back into stock whose line never took it out of stock, and says so on the timeline', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->sandwich)]);
    // after the sale the sandwich becomes a stocked product that goes back on a refund; the sale made it from its ingredients
    DB::table('items')->where('id', $m->sandwich->id)->update(['fulfilment' => 'to_stock', 'refund_restock' => true]);
    $movements = DB::table('stock_movements')->count();

    $refund = ($this->refund)($order['id']);

    $event = OrderEvent::query()->where('order_id', $order['id'])->where('event', 'line.refunded')->sole();
    expect($refund->lines[0]->disposition)->toBe('waste')
        ->and($event->payload['restock'])->toBeTrue()
        ->and($event->payload['disposition'])->toBe('waste')
        ->and($event->payload['movement_ids'])->toBe([])
        ->and(DB::table('stock_movements')->count())->toBe($movements);
});

it('refunds a line of a product deleted since the sale, and writes nothing to stock for it (the ledger takes live items only)', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->water)]);
    $m->water->delete();
    $movements = DB::table('stock_movements')->count();

    $refund = ($this->refund)($order['id']);

    $event = OrderEvent::query()->where('order_id', $order['id'])->where('event', 'line.refunded')->sole();
    expect($refund->amount)->toBe('30.00')
        ->and($refund->lines[0]->disposition)->toBe('waste')
        ->and($event->payload['restock'])->toBeTrue()
        ->and($refund->movementIds())->toBe([])
        ->and(DB::table('stock_movements')->count())->toBe($movements);
});

it('is asked by someone holding orders.void_request and approved by a holder of payments.void, and checks the state before any PIN is spent', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = ($this->closed)([$m->line($m->croissant), $m->line($m->water)]);
    $croissant = $f->lineOf($order, $m->croissant)['id'];
    $open = $f->send([$m->line($m->water)]);
    $written = ($this->written)();

    // the cashier asks; a cashier's PIN does not approve a refund (payments.void: admin, manager)
    $approver = ($this->refusal)(fn () => ($this->refund)($order['id'], [$croissant], null, $f->cashier->id));
    expect($approver?->messageKey)->toBe('orders.refund.approver_forbidden')->and($approver?->status)->toBe(403);

    // a waiter acting by short code on the shared till cannot ask for one
    withActor($f->cashier->id, $f->waiter->id);
    $asker = null;
    try {
        $this->orders->refund($order['id'], 'Customer returned it', $f->manager, [$croissant], (string) Str::uuid());
    } catch (OrderError $e) {
        $asker = $e;
    }
    expect($asker?->messageKey)->toBe('auth.forbidden')->and(($this->written)())->toBe($written);

    // the state checks an endpoint makes before it asks Identity for the PIN: the same refusals, nothing written
    expect(($this->refusal)(fn () => $this->orders->assertRefundable($order['id'], [987654]))?->messageKey)->toBe('orders.refund.line_invalid')
        ->and(fn () => $this->orders->assertRefundable($order['id'], [$croissant]))->not->toThrow(OrderError::class);
    expect(($this->refusal)(fn () => $this->orders->assertRefundable($open['id'], []))?->messageKey)->toBe('orders.refund.not_refundable')
        ->and(($this->written)())->toBe($written);

    // the admin approves as a manager does
    withActor($f->cashier->id);
    $refund = $this->orders->refund($order['id'], 'Customer returned it', $f->admin, [$croissant], (string) Str::uuid());
    expect((int) DB::table('order_refunds')->where('id', $refund->id)->value('approved_by_user_id'))->toBe($f->admin->id);
});
