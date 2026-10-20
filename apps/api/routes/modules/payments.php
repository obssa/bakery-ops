<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\PaymentsController;
use Illuminate\Support\Facades\Route;

/*
 * Payments routes under /api/v1 (owned by App\Modules\Payments, loaded by PaymentsServiceProvider). Every route:
 * auth:sanctum, the actor middleware (branch, actor, the acting-as session of a shared till, the device) and
 * payments.take (admin, manager, cashier; rule 6). The write also carries idempotency (rule 7) and a client_uuid in
 * the body. {order} is a number the action looks up through Orders' contract (another branch's order is 404). Both
 * routes are listed in tests/Support/route_permissions.php and the write in tests/Support/write_endpoints.php.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor', 'permission:payments.take'])->group(function (): void {
    Route::get('/payment-channels', [PaymentsController::class, 'channels'])->name('payments.channels');
    Route::post('/orders/{order}/payments', [PaymentsController::class, 'store'])->whereNumber('order')->middleware('idempotency')->name('payments.store');
});
