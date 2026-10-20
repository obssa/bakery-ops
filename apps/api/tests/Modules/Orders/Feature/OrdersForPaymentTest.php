<?php

declare(strict_types=1);

use App\Modules\Orders\Contracts\OrderPaymentView;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Orders\Contracts\OrderTotalsView;
use App\Modules\Orders\Models\Order;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrderFloor;

/*
 * The provided capability Orders::forPayment(orderId, lock) (manifest orders.provides; phase-1 plan section 6, step
 * 1.4): what Payments needs to decide whether it may take a payment, through Orders\Contracts only: the status, whether
 * the order is settled already, and its money. With lock it takes the order's row lock (SELECT ... FOR UPDATE) inside
 * the caller's transaction, the same lock ApplyPayment takes, so two payments of one order run one after the other.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
});

it('answers the status, whether it is settled, and the totals', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich), $m->line($m->croissant)]);

    $view = app(Orders::class)->forPayment($order['id']);

    expect($view)->toBeInstanceOf(OrderPaymentView::class)
        ->and($view->orderId)->toBe($order['id'])
        ->and($view->status)->toBe('sent')
        ->and($view->settled)->toBeFalse()
        ->and($view->totals)->toBeInstanceOf(OrderTotalsView::class)
        ->and($view->totals->toArray())->toBe(app(Orders::class)->totals($order['id'])->toArray())
        ->and($view->totals->balance)->toBe('310.00');

    Order::query()->whereKey($order['id'])->update(['settled_at' => now(), 'paid_total' => '310.00']);
    $settled = app(Orders::class)->forPayment($order['id']);
    expect($settled->settled)->toBeTrue()->and($settled->totals->balance)->toBe('0.00');

    $cart = $f->send([$m->line($m->water)], null, ['send' => false]);
    expect(app(Orders::class)->forPayment($cart['id'])->status)->toBe('open');
})->group('module:orders', 'feature');

it('does not find another branch\'s order or an unknown one', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($f->menu->water)]);
    actInBranch(Branch::factory()->create());

    expect(fn () => app(Orders::class)->forPayment($order['id']))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(Orders::class)->forPayment(999999999))->toThrow(ModelNotFoundException::class);
})->group('module:orders', 'feature');

it('takes the row lock only when asked, inside the caller\'s transaction', function () {
    $f = $this->floor;
    $order = $f->send([$f->menu->line($f->menu->water)]);
    $queries = [];
    DB::listen(function (QueryExecuted $q) use (&$queries): void {
        $queries[] = strtolower($q->sql);
    });

    app(Orders::class)->forPayment($order['id']);
    $plain = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'for update')));
    $queries = [];
    DB::transaction(fn () => app(Orders::class)->forPayment($order['id'], true));
    $locked = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `orders`') && str_contains($sql, 'for update')));

    expect($plain)->toBe([])->and($locked)->toHaveCount(1);
})->group('module:orders', 'feature');
