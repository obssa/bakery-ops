<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\OrderLinesController;
use App\Modules\Orders\Http\Controllers\OrdersController;
use Illuminate\Support\Facades\Route;

/*
 * Orders routes under /api/v1 (owned by App\Modules\Orders, loaded by OrdersServiceProvider; kept out of app/ so PSR-4
 * class discovery never executes it). Every route: auth:sanctum, the actor middleware (branch, actor, the acting-as
 * session of a shared pad, the device), a permission (rule 6): orders.create (admin, manager, cashier, waiter) to take,
 * change, send and cancel an order and to serve a ready line; orders.accept (chef, barista; admin and manager anywhere)
 * for the station actions accept, ready and reject, checked again at the line's own station by the controller; every
 * write also carries idempotency (rule 7). {order} and {line} bind through the BranchScoped models, so another
 * branch's order or line is 404. Every route is listed in tests/Support/route_permissions.php and every write in
 * tests/Support/write_endpoints.php.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::middleware(['permission:orders.create', 'idempotency'])->group(function (): void {
        Route::post('/orders', [OrdersController::class, 'store'])->name('orders.store');
        Route::put('/orders/{order}/lines', [OrdersController::class, 'replaceLines'])->whereNumber('order')->name('orders.lines.replace');
        Route::post('/orders/{order}/send', [OrdersController::class, 'send'])->whereNumber('order')->name('orders.send');
        Route::post('/orders/{order}/cancel', [OrdersController::class, 'cancel'])->whereNumber('order')->name('orders.cancel');
        Route::post('/order-lines/{line}/served', [OrderLinesController::class, 'served'])->whereNumber('line')->name('order-lines.served');
    });
    Route::middleware(['permission:orders.accept', 'idempotency'])->group(function (): void {
        Route::post('/order-lines/{line}/accept', [OrderLinesController::class, 'accept'])->whereNumber('line')->name('order-lines.accept');
        Route::post('/order-lines/{line}/ready', [OrderLinesController::class, 'ready'])->whereNumber('line')->name('order-lines.ready');
        Route::post('/order-lines/{line}/reject', [OrderLinesController::class, 'reject'])->whereNumber('line')->name('order-lines.reject');
    });
});
