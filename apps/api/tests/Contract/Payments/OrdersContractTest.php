<?php

declare(strict_types=1);

use App\Modules\Orders\Contracts\Orders;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Shared\Events\OrderPaid;
use App\Shared\Events\OrderRefunded;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Events\PaymentVoided;
use App\Shared\Models\Branch;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;
use Tests\Support\Shifts;

/*
 * Contract: payments -> orders (manifest contracts["payments->orders"]; phase-1 plan sections 2 and 5, step 1.4).
 *
 * Written BEFORE any code exists in App\Modules\Payments: only Orders' public side (Orders\Contracts and the events it
 * reacts to) and the events of App\Shared. Each case is phrased from Payments' side: "when I, Payments, record a
 * payment, I ask Orders for the totals, then I dispatch PaymentRecorded inside my transaction; I rely on Orders to add
 * the amount, to settle the order and, once it is served, to make it paid, sell its display-case lines and close it".
 *
 * Phase 4 (step 4.1a) appends the cases 5 to 13 at the end of the file: the refund path, the void of a payment, part payments and
 * the extra totals. They are RED until step 4.1b builds `Orders::refund`, the `ReversePayment` listener and `Inventory\Reversals`,
 * and carry the group `contract:payments->orders:refund`, which no CI job runs yet: `mod-orders` keeps running the four Phase 1
 * cases under `contract:payments->orders`. Step 4.1b gives the cases the first group name too, once they are green. Step 4.3c took over
 * the void side of 4.1b (PaymentVoided, Orders' ReversePayment, payment.voided on the timeline), so case 10 (the void) carries both names.
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
        // one sale row per display-case line, carrying the line's ref (stock leaves: the quantity is negative)
        ->and($f->moved($croissantRef, 'sale', [$m->croissant], $m->display))->toBe([$m->croissant->id => '-2.0000'])
        ->and($f->moved($waterRef, 'sale', [$m->water], $m->display))->toBe([$m->water->id => '-1.0000'])
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
        ->and($f->moved($croissantRef, 'sale', [$m->croissant], $m->display))->toBe([$m->croissant->id => '-1.0000'])
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

// Step 4.3b: the payment lists name each payment's order (GET /payments: {id, order_no, business_date, table_code, total}) and filter by
// the order's number, so Payments asks Orders for both, by id, never through Orders' models.

it('answers the summaries of the branch\'s orders by id: number, business date, table and total, and leaves out what it does not know', function () {
    $f = $this->floor;
    $m = $f->menu;
    $sent = $f->send([$m->line($m->sandwich), $m->line($m->water)]);
    $cart = $f->send([$m->line($m->water)], null, ['send' => false]);
    $theirs = Order::factory()->sent(1)->create(['branch_id' => Branch::factory()->create()->id]);

    $summaries = app(Orders::class)->summaries([$cart['id'], $sent['id'], $theirs->id, 987654]);

    expect(array_keys($summaries))->toEqualCanonicalizing([$sent['id'], $cart['id']])
        ->and($summaries[$sent['id']]->toArray())->toBe([
            'id' => $sent['id'], 'order_no' => $sent['order_no'], 'business_date' => $sent['business_date'], 'table_code' => $sent['table_code'], 'total' => '280.00',
        ])
        ->and($summaries[$cart['id']]->toArray())->toMatchArray(['order_no' => null, 'business_date' => null, 'total' => '30.00'])
        ->and(app(Orders::class)->summaries([]))->toBe([]);
})->group('contract:payments->orders');

it('finds the branch\'s orders by their number, on any business date', function () {
    $f = $this->floor;
    $m = $f->menu;
    $first = $f->send([$m->line($m->water)]);
    $f->send([$m->line($m->water)]);
    $older = Order::factory()->sent((int) $first['order_no'])->create(['branch_id' => $f->branch->id, 'business_date' => '2026-01-05', 'created_by_user_id' => $f->admin->id]);
    Order::factory()->sent((int) $first['order_no'])->create(['branch_id' => Branch::factory()->create()->id]);

    expect(app(Orders::class)->idsNumbered((int) $first['order_no']))->toBe([$first['id'], $older->id])
        ->and(app(Orders::class)->idsNumbered(99999))->toBe([]);
})->group('contract:payments->orders');

// Step 4.3e: a receipt prints the order (number, business date, type, table, when it was settled) and its charged lines with their modifiers
// and money, in both languages; Payments asks Orders for that through one read, never through Orders' models. No person is named in it.

it('answers what a receipt prints of an order: number, date, type, table, the charged lines with their modifiers, and the money', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich, '2', [$m->extraChicken, $m->noOnion]), $m->line($m->latte, '1', [$m->large]), $m->line($m->water, '3')]);
    $sandwich = $f->lineOf($order, $m->sandwich);
    $f->act('reject', $f->lineOf($order, $m->latte)['id'], $f->barista, ['reason' => 'ingredient_spoiled'])->assertOk();

    $view = app(Orders::class)->forReceipt($order['id']);

    expect($view->orderId)->toBe($order['id'])
        ->and($view->orderNo)->toBe($order['order_no'])
        ->and($view->businessDate)->toBe($order['business_date'])
        ->and($view->type)->toBe($order['type'])
        ->and($view->tableCode)->toBe($order['table_code'])
        ->and($view->status)->toBe(($this->status)($order['id']))
        ->and($view->settledAt)->toBeNull()
        ->and($view->totals->toArray())->toBe(app(Orders::class)->totals($order['id'])->toArray())
        ->and($view->totals->total)->toBe('710.00', 'two sandwiches with extra chicken (2 x 310.00) and three waters; the rejected latte is not charged')
        ->and(array_map(fn ($line) => $line->toArray(), $view->lines))->toBe([
            [
                'line_id' => $sandwich['id'], 'item_id' => $m->sandwich->id, 'name_en' => 'Chicken sandwich', 'name_am' => $m->sandwich->name_am,
                'qty' => '2.0000', 'unit_price' => '250.00', 'modifier_price' => '60.00', 'line_total' => '620.00',
                'modifiers' => [
                    ['name_en' => 'No onion', 'name_am' => 'ያለ ሽንኩርት', 'price_delta' => '0.00'],
                    ['name_en' => 'Extra chicken', 'name_am' => 'ተጨማሪ ዶሮ', 'price_delta' => '60.00'],
                ],
            ],
            [
                'line_id' => $f->lineOf($order, $m->water)['id'], 'item_id' => $m->water->id, 'name_en' => $m->water->name_en, 'name_am' => $m->water->name_am,
                'qty' => '3.0000', 'unit_price' => '30.00', 'modifier_price' => '0.00', 'line_total' => '90.00', 'modifiers' => [],
            ],
        ]);

    // settled and closed once paid in full; another branch's order is not found
    ($this->serveAll)(['lines' => [$sandwich, $f->lineOf($order, $m->water)]]);
    ($this->pay)($order['id']);
    $paid = app(Orders::class)->forReceipt($order['id']);
    $theirs = Order::factory()->sent(1)->create(['branch_id' => Branch::factory()->create()->id]);
    expect($paid->status)->toBe('closed')
        ->and($paid->settledAt?->toIso8601String())->toBe(Order::query()->findOrFail($order['id'])->settled_at?->toIso8601String())
        ->and($paid->totals->balance)->toBe('0.00')
        ->and(fn () => app(Orders::class)->forReceipt($theirs->id))->toThrow(ModelNotFoundException::class);
})->group('contract:payments->orders');

// ===================================================================================================================================
// Phase 4 (step 4.1a, plan section 2). Red until step 4.1b. The signature the cases pin (manifest `modules.orders`, plan 10.9 item 8):
//
//   Orders::refund(int $orderId, string $reason, User $approver, array $lines, array $dispositions, string $clientUuid): void
//
// $lines are order-line ids (empty: the whole order, with the default dispositions of plan 10.2 R-C, still open with Ramin);
// $dispositions map a line id to 'restock' | 'waste' | 'none'. The approver's PIN is checked by the controller, never here.
// A refusal carries a message_key (an OrderError). Nothing here builds any refund stock logic: these cases only state what 4.1b must make true.
// ===================================================================================================================================

const REFUND_GROUP = 'contract:payments->orders:refund';

beforeEach(function () {
    // an order that is paid, served and closed: [order (API shape), croissant line, sandwich line, croissant ref, sandwich ref]
    $this->closedOrder = function (): array {
        $f = $this->floor;
        $m = $f->menu;
        $order = $f->send([$m->line($m->sandwich), $m->line($m->croissant, '2')]);
        ($this->serveAll)($order);
        ($this->pay)($order['id']);
        expect(($this->status)($order['id']))->toBe('closed');
        $croissant = $f->lineOf($order, $m->croissant);
        $sandwich = $f->lineOf($order, $m->sandwich);

        return [$order, $croissant, $sandwich, $f->refOf($croissant['client_uuid']), $f->refOf($sandwich['client_uuid'])];
    };
    $this->refund = function (int $orderId, array $lines = [], array $dispositions = [], string $reason = 'customer returned it', ?string $uuid = null): void {
        app(Orders::class)->refund($orderId, $reason, $this->floor->manager, $lines, $dispositions, $uuid ?? (string) Str::uuid());
    };
    // the message_key of the OrderError a call throws, null when it did not throw
    $this->refusal = function (Closure $call): ?string {
        try {
            $call();
        } catch (Throwable $e) {
            // only a refusal with a message_key is an answer; anything else is a failure of the test or of the code
            if (! property_exists($e, 'messageKey')) {
                throw $e;
            }

            return $e->messageKey;
        }

        return null;
    };
    $this->movementRows = fn (string $ref, string $reason): array => DB::table('stock_movements')
        ->where('reservation_ref', $ref)->where('reason', $reason)->orderBy('id')->get()
        ->map(fn (object $r): array => (array) $r)->all();
});

it('refunds a restocked display-case line with ONE reversing return movement that points at the sale and leaves the sale untouched', function () {
    $f = $this->floor;
    $m = $f->menu;
    [$order, $croissant, , $croissantRef] = ($this->closedOrder)();
    $sale = ($this->movementRows)($croissantRef, 'sale');
    expect($sale)->toHaveCount(1);
    $onHandAfterSale = $f->level($m->croissant, $m->display)->onHand->toString();

    ($this->refund)($order['id'], [$croissant['id']], [$croissant['id'] => 'restock']);

    $returns = DB::table('stock_movements')->where('reason', 'return')->where('ref_type', 'stock_movement')->where('ref_id', $sale[0]['id'])->get();
    expect($returns)->toHaveCount(1)
        ->and((string) $returns[0]->qty)->toBe('2.0000') // the sale was -2: the stock comes back
        ->and((int) $returns[0]->item_id)->toBe($m->croissant->id)
        ->and((int) ($returns[0]->to_location_id ?? $returns[0]->from_location_id))->toBe($m->display->id)
        ->and(DB::table('stock_movement_reversals')->where('reverses_movement_id', $sale[0]['id'])->where('movement_id', $returns[0]->id)->count())->toBe(1)
        ->and($f->level($m->croissant, $m->display)->onHand->toString())->toBe(BigDecimal::of($onHandAfterSale)->plus('2')->toScale(4)->__toString())
        // the original is byte-identical when read again
        ->and(($this->movementRows)($croissantRef, 'sale'))->toBe($sale);
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group(REFUND_GROUP);

it('writes no movement for a line refunded as waste or none, and the originals stand', function () {
    $f = $this->floor;
    [$order, $croissant, $sandwich, $croissantRef, $sandwichRef] = ($this->closedOrder)();
    $before = DB::table('stock_movements')->where('branch_id', $f->branch->id)->count();
    $consumed = ($this->movementRows)($sandwichRef, 'production_consume');
    $sold = ($this->movementRows)($croissantRef, 'sale');
    expect($consumed)->not->toBe([]);

    ($this->refund)($order['id'], [$sandwich['id'], $croissant['id']], [$sandwich['id'] => 'waste', $croissant['id'] => 'none']);

    expect(DB::table('stock_movements')->where('branch_id', $f->branch->id)->count())->toBe($before)
        ->and(DB::table('stock_movement_reversals')->count())->toBe(0)
        ->and(($this->movementRows)($sandwichRef, 'production_consume'))->toBe($consumed)
        ->and(($this->movementRows)($croissantRef, 'sale'))->toBe($sold);
})->group(REFUND_GROUP);

it('reverses a movement once: a second refund of the line is refused and so is a second reversal row for the same original', function () {
    $f = $this->floor;
    [$order, $croissant, , $croissantRef] = ($this->closedOrder)();
    ($this->refund)($order['id'], [$croissant['id']], [$croissant['id'] => 'restock']);
    $movements = DB::table('stock_movements')->count();

    $second = ($this->refusal)(fn () => ($this->refund)($order['id'], [$croissant['id']], [$croissant['id'] => 'restock']));

    expect($second)->toBe('orders.refund.line_refunded')
        ->and(DB::table('stock_movements')->count())->toBe($movements)
        ->and(DB::table('stock_movement_reversals')->count())->toBe(1);
    // the database says it too: an original can be named by one reversal row only
    $sale = ($this->movementRows)($croissantRef, 'sale')[0];
    $duplicate = fn () => DB::table('stock_movement_reversals')->insert([
        'branch_id' => $f->branch->id, 'movement_id' => $sale['id'], 'reverses_movement_id' => $sale['id'], 'created_at' => now(),
    ]);
    expect($duplicate)->toThrow(QueryException::class);
})->group(REFUND_GROUP);

it('refunds only a closed order, with a reason; it stays closed, carries refunded_total and the timeline, and OrderRefunded follows the commit', function () {
    $f = $this->floor;
    $m = $f->menu;
    // refused: sent, served-unpaid, settled-but-not-served
    $sent = $f->send([$m->line($m->sandwich), $m->line($m->croissant)]);
    $served = $f->send([$m->line($m->sandwich), $m->line($m->croissant)]);
    ($this->serveAll)($served);
    $settled = $f->send([$m->line($m->sandwich), $m->line($m->croissant)]);
    ($this->pay)($settled['id']);
    foreach (['sent' => $sent, 'served' => $served, 'settled but not served' => $settled] as $what => $o) {
        expect(($this->refusal)(fn () => ($this->refund)($o['id'])))->toBe('orders.refund.not_refundable', $what);
    }

    [$order, $croissant] = ($this->closedOrder)();
    $refunded = [];
    Event::listen(OrderRefunded::class, function (OrderRefunded $e) use (&$refunded): void {
        $refunded[] = $e->payload();
    });
    expect(($this->refusal)(fn () => ($this->refund)($order['id'], [$croissant['id']], [$croissant['id'] => 'restock'], '  ')))->toBe('orders.refund.reason_required')
        ->and($refunded)->toBe([]);

    ($this->refund)($order['id'], [$croissant['id']], [$croissant['id'] => 'restock']);

    $row = Order::query()->findOrFail($order['id']);
    $lineTotal = BigDecimal::of($croissant['line_total'] ?? $croissant['total'])->toScale(2)->__toString();
    expect($row->status->value)->toBe('closed')
        ->and($row->refunded_total->amount())->toBe($lineTotal)
        ->and(BigDecimal::of($row->refunded_total->amount())->isLessThanOrEqualTo($row->paid_total->amount()))->toBeTrue()
        ->and($row->refunded_at)->not->toBeNull()
        ->and(($this->timeline)($order['id']))->toContain('line.refunded')->toContain('order.refunded')
        ->and($refunded)->toHaveCount(1)
        ->and($refunded[0]['order_id'])->toBe($order['id'])
        ->and($refunded[0]['amount'])->toBe($lineTotal);

    // the lines already refunded cannot come back again, so a whole-order refund after a line refund is refused
    expect(($this->refusal)(fn () => ($this->refund)($order['id'])))->toBe('orders.refund.line_refunded');
})->group(REFUND_GROUP);

it('does not announce OrderRefunded, write a movement or a timeline row when the refund rolls back', function () {
    [$order, $croissant] = ($this->closedOrder)();
    $refunded = [];
    Event::listen(OrderRefunded::class, function (OrderRefunded $e) use (&$refunded): void {
        $refunded[] = $e->payload();
    });
    $events = count(($this->timeline)($order['id']));
    $movements = DB::table('stock_movements')->count();

    try {
        DB::transaction(function () use ($order, $croissant): void {
            ($this->refund)($order['id'], [$croissant['id']], [$croissant['id'] => 'restock']);
            throw new RuntimeException('payment_refunds insert failed'); // what Payments does after Orders::refund
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('payment_refunds insert failed');
    }

    expect($refunded)->toBe([])
        ->and(count(($this->timeline)($order['id'])))->toBe($events)
        ->and(DB::table('stock_movements')->count())->toBe($movements)
        ->and(Order::query()->findOrFail($order['id'])->refunded_total->amount())->toBe('0.00');
})->group(REFUND_GROUP);

it('does not bypass the shift rule: restocking a station line while its station has no open shift is refused and writes nothing', function () {
    $f = $this->floor;
    [$order, , $sandwich] = ($this->closedOrder)();
    // the kitchen shift ends; the ledger refuses a station movement outside a shift (ADR 0013)
    DB::table('shift_instances')->where('station_id', $f->menu->kitchenStation->id)->update([
        'status' => 'cancelled', 'open_key' => null, 'cancelled_at' => now(), 'cancelled_by_user_id' => $f->admin->id, 'cancel_reason' => 'test: no shift',
    ]);
    $movements = DB::table('stock_movements')->count();

    $refused = ($this->refusal)(fn () => ($this->refund)($order['id'], [$sandwich['id']], [$sandwich['id'] => 'restock']));

    expect($refused)->toBe('inventory.shift.required')
        ->and(DB::table('stock_movements')->count())->toBe($movements)
        ->and(DB::table('stock_movement_reversals')->count())->toBe(0)
        ->and(Order::query()->findOrFail($order['id'])->refunded_total->amount())->toBe('0.00');
})->group(REFUND_GROUP)->skip(fn () => ! Shifts::enforced(), 'the shift rules are off (rules-off-gates)');

it('takes a voided payment out of paid_total while the order is sent..served, and refuses it once the order is paid or closed', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich), $m->line($m->croissant)]);
    ($this->pay)($order['id'], null, null, 1); // settles, not served
    expect(Order::query()->findOrFail($order['id'])->settled_at)->not->toBeNull();
    $amount = ($this->totals)($order['id'])->paid;

    DB::transaction(function () use ($order, $amount, $f): void {
        withActor($f->cashier->id);
        event(new PaymentVoided($f->branch->id, 1, $order['id'], 'cash', $amount, 'wrong amount keyed', $f->cashier->id, $f->manager->id));
    });

    $row = Order::query()->findOrFail($order['id']);
    expect($row->paid_total->amount())->toBe('0.00')
        ->and($row->settled_at)->toBeNull()
        ->and($row->status->value)->toBe('sent')
        ->and(($this->timeline)($order['id']))->toContain('payment.voided')
        ->and(($this->totals)($order['id'])->balance)->toBe($order['totals']['total']);

    // a closed order cannot be un-paid by voiding a payment: it is refunded instead
    [$closed] = ($this->closedOrder)();
    $closedPaid = ($this->totals)($closed['id'])->paid;
    $thrown = ($this->refusal)(function () use ($closed, $closedPaid, $f): void {
        DB::transaction(function () use ($closed, $closedPaid, $f): void {
            withActor($f->cashier->id);
            event(new PaymentVoided($f->branch->id, 2, $closed['id'], 'cash', $closedPaid, 'oops', $f->cashier->id, $f->manager->id));
        });
    });
    expect($thrown)->toBe('payments.void.order_closed')
        ->and(Order::query()->findOrFail($closed['id'])->paid_total->amount())->toBe($closedPaid);
})->group(REFUND_GROUP, 'contract:payments->orders'); // step 4.3c built the void side (PaymentVoided, ReversePayment): CI runs it (mod-orders)

it('keeps the order unsettled after a part payment, settles it with the last one, and sells the display-case lines exactly once', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich), $m->line($m->croissant, '2')]);
    ($this->serveAll)($order);
    $ref = $f->refOf($f->lineOf($order, $m->croissant)['client_uuid']);

    ($this->pay)($order['id'], '100.00', null, 1);
    expect(Order::query()->findOrFail($order['id'])->settled_at)->toBeNull()
        ->and(($this->movementRows)($ref, 'sale'))->toBe([]);

    ($this->pay)($order['id'], null, null, 2);

    expect(($this->status)($order['id']))->toBe('closed')
        ->and(($this->totals)($order['id'])->paid)->toBe($order['totals']['total'])
        ->and(($this->movementRows)($ref, 'sale'))->toHaveCount(1)
        ->and($this->paidEvents)->toHaveCount(1);
})->group(REFUND_GROUP);

it('answers refunded and net_paid as decimal strings next to total, paid and balance', function () {
    [$order, $croissant] = ($this->closedOrder)();
    $before = app(Orders::class)->totals($order['id']);
    expect($before->refunded)->toBe('0.00')->and($before->netPaid)->toBe($before->paid);

    ($this->refund)($order['id'], [$croissant['id']], [$croissant['id'] => 'restock']);

    $after = app(Orders::class)->totals($order['id']);
    expect($after->refunded)->toBeString()->not->toBe('0.00')
        ->and($after->netPaid)->toBe(BigDecimal::of($after->paid)->minus($after->refunded)->toScale(2)->__toString())
        ->and($after->paid)->toBe($before->paid); // paid is what came in; the refund is its own figure
})->group(REFUND_GROUP);
