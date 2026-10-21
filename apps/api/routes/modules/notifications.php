<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\EndpointsController;
use App\Modules\Notifications\Http\Controllers\NotificationsController;
use App\Modules\Notifications\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Notifications routes under /api/v1 (owned by App\Modules\Notifications, loaded by NotificationsServiceProvider; kept out of
 * app/ so PSR-4 class discovery never executes it). Every route: auth:sanctum, the actor middleware (branch + actor) and
 * notifications.view (rule 6), which every role holds: the lines are always the caller's own, so there is nothing further to
 * ask. The writes also carry idempotency (rule 7). Every route is listed in tests/Support/route_permissions.php and every
 * write in tests/Support/write_endpoints.php.
 *
 * Step 2.5b adds the channels, the endpoints and the Telegram link beside these, and the one public route: the Telegram webhook,
 * which Telegram calls without a bearer token and which is guarded by the secret in its path and header instead (listed in
 * tests/Support/public_routes.php; it is not a user write).
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor', 'permission:notifications.view'])->group(function (): void {
    Route::get('/notifications', [NotificationsController::class, 'index'])->name('notifications.index');

    Route::middleware('idempotency')->group(function (): void {
        Route::post('/notifications/read-all', [NotificationsController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notifications/{notification}/read', [NotificationsController::class, 'read'])->whereNumber('notification')->name('notifications.read');
        Route::post('/notifications/endpoints', [EndpointsController::class, 'store'])->name('notifications.endpoints.store');
        Route::delete('/notifications/endpoints/{endpoint}', [EndpointsController::class, 'destroy'])->whereNumber('endpoint')->name('notifications.endpoints.destroy');
        Route::post('/notifications/telegram/link', [EndpointsController::class, 'link'])->name('notifications.telegram.link');
    });

    Route::get('/notifications/channels', [EndpointsController::class, 'channels'])->name('notifications.channels');
});

Route::prefix('api/v1')->middleware(['api'])->group(function (): void {
    Route::post('/telegram/webhook/{secret}', TelegramWebhookController::class)->name('telegram.webhook');
});
