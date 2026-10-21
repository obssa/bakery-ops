<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\NotificationsController;
use Illuminate\Support\Facades\Route;

/*
 * Notifications routes under /api/v1 (owned by App\Modules\Notifications, loaded by NotificationsServiceProvider; kept out of
 * app/ so PSR-4 class discovery never executes it). Every route: auth:sanctum, the actor middleware (branch + actor) and
 * notifications.view (rule 6), which every role holds: the lines are always the caller's own, so there is nothing further to
 * ask. The writes also carry idempotency (rule 7). Every route is listed in tests/Support/route_permissions.php and every
 * write in tests/Support/write_endpoints.php.
 *
 * Step 2.5b adds the channels, the endpoints and the Telegram link and webhook beside these.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor', 'permission:notifications.view'])->group(function (): void {
    Route::get('/notifications', [NotificationsController::class, 'index'])->name('notifications.index');

    Route::middleware('idempotency')->group(function (): void {
        Route::post('/notifications/read-all', [NotificationsController::class, 'readAll'])->name('notifications.read-all');
        Route::post('/notifications/{notification}/read', [NotificationsController::class, 'read'])->whereNumber('notification')->name('notifications.read');
    });
});
