<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\ChannelsController;
use App\Modules\Payments\Http\Controllers\PaymentsController;
use App\Modules\Payments\Http\Controllers\ReceiptSettingsController;
use Illuminate\Support\Facades\Route;

/*
 * Payments routes under /api/v1 (owned by App\Modules\Payments, loaded by PaymentsServiceProvider). Every route:
 * auth:sanctum, the actor middleware (branch, actor, the acting-as session of a shared till, the device) and a permission (rule 6);
 * every write also carries idempotency (rule 7). The till's routes ask for payments.take (admin, manager, cashier); the payment
 * write carries a client_uuid in the body and {order} is a number the action looks up through Orders' contract (another branch's
 * order is 404). Step 4.3a adds the channel administration (channels.manage, the admin; {channel} binds through the BranchScoped
 * model, so another branch's or a removed channel is 404) and the receipt settings read (a placeholder until step 4.3e). Every route
 * is listed in tests/Support/route_permissions.php and every write in tests/Support/write_endpoints.php.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::middleware('permission:payments.take')->group(function (): void {
        Route::get('/payment-channels', [ChannelsController::class, 'index'])->name('payments.channels');
        Route::post('/orders/{order}/payments', [PaymentsController::class, 'store'])->whereNumber('order')->middleware('idempotency')->name('payments.store');
        Route::get('/receipt-settings', [ReceiptSettingsController::class, 'show'])->name('payments.receipt-settings.show');
    });

    Route::middleware(['permission:channels.manage', 'idempotency'])->group(function (): void {
        Route::post('/payment-channels', [ChannelsController::class, 'store'])->name('payments.channels.store');
        Route::put('/payment-channels/order', [ChannelsController::class, 'reorder'])->name('payments.channels.reorder');
        Route::patch('/payment-channels/{channel}', [ChannelsController::class, 'update'])->whereNumber('channel')->name('payments.channels.update');
        Route::delete('/payment-channels/{channel}', [ChannelsController::class, 'destroy'])->whereNumber('channel')->name('payments.channels.destroy');
    });
});
