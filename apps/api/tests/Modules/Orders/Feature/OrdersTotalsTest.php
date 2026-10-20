<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Orders\Contracts\OrderTotalsView;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Orders\Models\Order;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\OrderFloor;

/*
 * The provided capability Orders::totals(orderId) (manifest orders.provides; docs/phases/phase-1-plan.md section 6,
 * step 1.3e): an order's money as decimal strings (ADR 0004): subtotal, discount, tax, total, paid and balance (total
 * minus paid) in the order's currency. A voided or rejected line is not charged, modifiers are, and another branch's
 * order is not found (rule 8). Payments reads this when it takes a payment (step 1.4), through Orders\Contracts only.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->orders = fn (): Orders => app(Orders::class);
});

it('answers decimal strings for a sent order, modifiers included', function () {
    $f = $this->floor;
    $m = $f->menu;
    // 2 x (250.00 + 60.00) + (120.00 + 20.00) + 60.00 = 820.00
    $order = $f->send([$m->line($m->sandwich, '2', [$m->extraChicken]), $m->line($m->latte, '1', [$m->large]), $m->line($m->croissant)]);

    $view = ($this->orders)()->totals($order['id']);

    expect($view)->toBeInstanceOf(OrderTotalsView::class)
        ->and($view->subtotal)->toBeString()->toBe('820.00')
        ->and($view->discount)->toBe('0.00')
        ->and($view->tax)->toBe('0.00')
        ->and($view->total)->toBe('820.00')
        ->and($view->paid)->toBe('0.00')
        ->and($view->balance)->toBe('820.00')
        ->and($view->currency)->toBe('ETB')
        ->and($view->toArray())->toBe([
            'subtotal' => '820.00', 'discount' => '0.00', 'tax' => '0.00', 'total' => '820.00', 'paid' => '0.00', 'balance' => '820.00', 'currency' => 'ETB',
        ])
        ->and($view->toArray())->toMatchArray(['total' => $order['totals']['total'], 'balance' => $order['totals']['balance']]);
})->group('module:orders', 'feature');

it('does not charge a rejected line, a voided order or an unsent cart beyond its lines', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich), $m->line($m->latte, '2'), $m->line($m->croissant)]);
    expect(($this->orders)()->totals($order['id'])->total)->toBe('550.00');

    $f->act('reject', $f->lineOf($order, $m->latte)['id'], $f->barista, ['reason' => 'out_of_stock'])->assertOk();
    $afterReject = ($this->orders)()->totals($order['id']);
    expect($afterReject->subtotal)->toBe('310.00')->and($afterReject->total)->toBe('310.00')->and($afterReject->balance)->toBe('310.00');

    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    loginAs($f->cashier);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$order['id']}/void", ['reason' => 'Customer left', 'pin' => '246802'])->assertOk();
    $voided = ($this->orders)()->totals($order['id']);
    expect($voided->subtotal)->toBe('0.00')->and($voided->total)->toBe('0.00')->and($voided->balance)->toBe('0.00');

    $cart = $f->send([$m->line($m->latte)], null, ['send' => false]);
    expect(($this->orders)()->totals($cart['id'])->total)->toBe('120.00');
})->group('module:orders', 'feature');

it('takes what was paid off the balance and keeps two decimals', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich, '2')]);
    // Payments (step 1.4) keeps paid_total; here the column is set the way its listener will
    Order::query()->whereKey($order['id'])->update(['paid_total' => '300.00']);

    $view = ($this->orders)()->totals($order['id']);

    expect($view->total)->toBe('500.00')->and($view->paid)->toBe('300.00')->and($view->balance)->toBe('200.00');

    Order::query()->whereKey($order['id'])->update(['paid_total' => '500.00']);
    expect(($this->orders)()->totals($order['id'])->balance)->toBe('0.00');
})->group('module:orders', 'feature');

it('does not find an order of another branch, or one that does not exist', function () {
    $other = Branch::factory()->create();
    $foreign = Order::factory()->sent()->create(['branch_id' => $other->id]);

    expect(fn () => ($this->orders)()->totals($foreign->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => ($this->orders)()->totals(987654))->toThrow(ModelNotFoundException::class);
})->group('module:orders', 'feature');
