<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\DiningTablesController;
use App\Modules\Orders\Http\Controllers\MenuController;
use App\Modules\Orders\Http\Controllers\OrderLinesController;
use App\Modules\Orders\Http\Controllers\OrdersController;
use App\Modules\Orders\Http\Controllers\StationTicketsController;
use Illuminate\Support\Facades\Route;

/*
 * Orders routes under /api/v1 (owned by App\Modules\Orders, loaded by OrdersServiceProvider; kept out of app/ so PSR-4
 * class discovery never executes it). Every route: auth:sanctum, the actor middleware (branch, actor, the acting-as
 * session of a shared pad, the device), a permission (rule 6): orders.create (admin, manager, cashier, waiter) to take,
 * change, send and cancel an order and to serve a ready line; orders.accept (chef, barista; admin and manager anywhere)
 * for the station actions accept, ready and reject, checked again at the line's own station by the controller;
 * orders.void_request (admin, manager, cashier) to ask for a void, which a manager's or the admin's PIN approves;
 * orders.create to read the menu, the order list, one order and the dining tables, orders.accept to read a station's rail
 * (checked again at that station by the controller), tables.manage (admin) to add and change them; every
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
    // read models (step 1.5): conditional polls (ETag / 304, ADR 0009), no Idempotency-Key
    Route::middleware('permission:orders.create')->group(function (): void {
        Route::get('/menu', MenuController::class)->name('orders.menu');
        Route::get('/orders', [OrdersController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrdersController::class, 'show'])->whereNumber('order')->name('orders.show');
    });
    Route::get('/stations/{station}/tickets', StationTicketsController::class)->middleware('permission:orders.accept')->whereNumber('station')->name('orders.station.tickets');
    Route::get('/dining-tables', [DiningTablesController::class, 'index'])->middleware('permission:orders.create')->name('orders.tables.index');
    Route::middleware(['permission:tables.manage', 'idempotency'])->group(function (): void {
        Route::post('/dining-tables', [DiningTablesController::class, 'store'])->name('orders.tables.store');
        Route::patch('/dining-tables/{table}', [DiningTablesController::class, 'update'])->whereNumber('table')->name('orders.tables.update');
    });
    Route::middleware(['permission:orders.void_request', 'idempotency'])->group(function (): void {
        Route::post('/orders/{order}/void', [OrdersController::class, 'void'])->whereNumber('order')->name('orders.void');
    });
    Route::middleware(['permission:orders.accept', 'idempotency'])->group(function (): void {
        Route::post('/order-lines/{line}/accept', [OrderLinesController::class, 'accept'])->whereNumber('line')->name('order-lines.accept');
        Route::post('/order-lines/{line}/ready', [OrderLinesController::class, 'ready'])->whereNumber('line')->name('order-lines.ready');
        Route::post('/order-lines/{line}/reject', [OrderLinesController::class, 'reject'])->whereNumber('line')->name('order-lines.reject');
    });
});
