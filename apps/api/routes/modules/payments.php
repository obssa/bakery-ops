<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\ChannelsController;
use App\Modules\Payments\Http\Controllers\PaymentDecisionsController;
use App\Modules\Payments\Http\Controllers\PaymentReadsController;
use App\Modules\Payments\Http\Controllers\PaymentReportsController;
use App\Modules\Payments\Http\Controllers\PaymentsController;
use App\Modules\Payments\Http\Controllers\ReceiptsController;
use App\Modules\Payments\Http\Controllers\ReceiptSettingsController;
use Illuminate\Support\Facades\Route;

/*
 * Payments routes under /api/v1 (owned by App\Modules\Payments, loaded by PaymentsServiceProvider). Every route:
 * auth:sanctum, the actor middleware (branch, actor, the acting-as session of a shared till, the device) and a permission (rule 6);
 * every write also carries idempotency (rule 7). The till's routes ask for payments.take (admin, manager, cashier); the payment
 * write carries a client_uuid in the body and {order} is a number the action looks up through Orders' contract (another branch's
 * order is 404). Step 4.3a adds the channel administration (channels.manage, the admin; {channel} binds through the BranchScoped
 * model, so another branch's or a removed channel is 404) and the receipt settings read (a placeholder until step 4.3e). Step 4.3b adds
 * the split bill (payments.take) and the payment reads (payments.view; {payment} is a number the controller looks up through the
 * BranchScoped model, so another branch's is 404). Step 4.3c adds verify and dispute (payments.verify: admin, manager) and the void (asked with
 * orders.void_request, approved inside by the PIN of a payments.void holder). Step 4.3e adds the receipts (the PDF: payments.view; a signed
 * link: payments.take), the receipt settings PUT (channels.manage), the reconciliation and the export (payments.verify), and the one public
 * route of Payments: GET /receipts/{order}, a customer's receipt behind a signed link (no bearer token; the `signed` middleware is the
 * protection, tests/Support/public_routes.php). /payments/reconciliation and /payments/export are not a {payment}: it takes digits only.
 * Every route is listed in tests/Support/route_permissions.php and every write in tests/Support/write_endpoints.php.
 */
Route::prefix('api/v1')->middleware(['api', 'signed'])->group(function (): void {
    Route::get('/receipts/{order}', [ReceiptsController::class, 'show'])->whereNumber('order')->name('payments.receipts.show');
});

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::middleware('permission:payments.take')->group(function (): void {
        Route::get('/payment-channels', [ChannelsController::class, 'index'])->name('payments.channels');
        Route::post('/orders/{order}/payments', [PaymentsController::class, 'store'])->whereNumber('order')->middleware('idempotency')->name('payments.store');
        Route::post('/orders/{order}/payment-batches', [PaymentsController::class, 'batch'])->whereNumber('order')->middleware('idempotency')->name('payments.batches.store');
        Route::get('/receipt-settings', [ReceiptSettingsController::class, 'show'])->name('payments.receipt-settings.show');
        Route::post('/orders/{order}/receipt-link', [ReceiptsController::class, 'link'])->whereNumber('order')->middleware('idempotency')->name('payments.receipt.link');
    });

    Route::middleware('permission:payments.view')->group(function (): void {
        Route::get('/orders/{order}/payments', [PaymentReadsController::class, 'forOrder'])->whereNumber('order')->name('payments.order');
        Route::get('/payments', [PaymentReadsController::class, 'index'])->name('payments.index');
        Route::get('/payments/{payment}', [PaymentReadsController::class, 'show'])->whereNumber('payment')->name('payments.show');
        Route::get('/orders/{order}/receipt.pdf', [ReceiptsController::class, 'pdf'])->whereNumber('order')->name('payments.receipt.pdf');
    });

    // step 4.3e: the payment checkers' reports (payments.verify)
    Route::middleware('permission:payments.verify')->group(function (): void {
        Route::get('/payments/reconciliation', [PaymentReportsController::class, 'reconciliation'])->name('payments.reconciliation');
        Route::get('/payments/export', [PaymentReportsController::class, 'export'])->name('payments.export');
    });

    // step 4.3c: the admin's decisions on a payment (payments.verify), and the void a manager's PIN approves (asked with orders.void_request)
    Route::middleware(['permission:payments.verify', 'idempotency'])->group(function (): void {
        Route::post('/payments/{payment}/verify', [PaymentDecisionsController::class, 'verify'])->whereNumber('payment')->name('payments.verify');
        Route::post('/payments/{payment}/dispute', [PaymentDecisionsController::class, 'dispute'])->whereNumber('payment')->name('payments.dispute');
    });
    Route::middleware(['permission:orders.void_request', 'idempotency'])->group(function (): void {
        Route::post('/payments/{payment}/void', [PaymentDecisionsController::class, 'void'])->whereNumber('payment')->name('payments.void');
    });

    Route::middleware(['permission:channels.manage', 'idempotency'])->group(function (): void {
        Route::post('/payment-channels', [ChannelsController::class, 'store'])->name('payments.channels.store');
        Route::put('/payment-channels/order', [ChannelsController::class, 'reorder'])->name('payments.channels.reorder');
        Route::patch('/payment-channels/{channel}', [ChannelsController::class, 'update'])->whereNumber('channel')->name('payments.channels.update');
        Route::delete('/payment-channels/{channel}', [ChannelsController::class, 'destroy'])->whereNumber('channel')->name('payments.channels.destroy');
        Route::put('/receipt-settings', [ReceiptSettingsController::class, 'update'])->name('payments.receipt-settings.update');
    });
});
