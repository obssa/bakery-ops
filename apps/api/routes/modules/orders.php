<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\OrdersController;
use Illuminate\Support\Facades\Route;

/*
 * Orders routes under /api/v1 (owned by App\Modules\Orders, loaded by OrdersServiceProvider; kept out of app/ so PSR-4
 * class discovery never executes it). Every route: auth:sanctum, the actor middleware (branch, actor, the acting-as
 * session of a shared pad, the device), a permission (rule 6): orders.create (admin, manager, cashier, waiter) to take,
 * change, send and cancel an order; every write also carries idempotency (rule 7). {order} binds through the
 * BranchScoped model, so another branch's order is 404. Every route is listed in tests/Support/route_permissions.php
 * and every write in tests/Support/write_endpoints.php.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::middleware(['permission:orders.create', 'idempotency'])->group(function (): void {
        Route::post('/orders', [OrdersController::class, 'store'])->name('orders.store');
        Route::put('/orders/{order}/lines', [OrdersController::class, 'replaceLines'])->whereNumber('order')->name('orders.lines.replace');
        Route::post('/orders/{order}/send', [OrdersController::class, 'send'])->whereNumber('order')->name('orders.send');
        Route::post('/orders/{order}/cancel', [OrdersController::class, 'cancel'])->whereNumber('order')->name('orders.cancel');
    });
});
