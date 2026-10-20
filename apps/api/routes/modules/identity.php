<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\BroadcastingAuthController;
use App\Modules\Identity\Http\Controllers\DevicesController;
use App\Modules\Identity\Http\Controllers\ShortCodeController;
use App\Modules\Identity\Http\Controllers\StationsController;
use App\Modules\Identity\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

/*
 * Identity routes under /api/v1 (owned by App\Modules\Identity, loaded by IdentityServiceProvider; kept out of app/
 * so PSR-4 class discovery never executes it). Public: login and the pad's short-code gate, both throttled. Everything else: auth:sanctum, the actor middleware (branch + actor + device + acting session),
 * a permission (rule 6) and, on writes, idempotency (rule 7). Every route is listed in
 * tests/Support/route_permissions.php and every write in tests/Support/write_endpoints.php.
 */
Route::prefix('api/v1')->middleware('api')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('identity.login');
    Route::post('/short-code/verify', [ShortCodeController::class, 'verify'])->middleware('throttle:short-code')->name('identity.short-code.verify');

    Route::middleware(['auth:sanctum', 'actor'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->middleware('can:identity.authenticated')->name('identity.me');
        Route::post('/logout', [AuthController::class, 'logout'])->middleware(['can:identity.authenticated', 'idempotency'])->name('identity.logout');
        // Live updates (ADR 0009, step 1.2): signs a private-channel subscription for Reverb or a Pusher-compatible
        // transport once one is configured; Broadcasting\ChannelRules decides the channel. It writes nothing, so it
        // carries no idempotency (tests/Support/read_like_writes.php).
        Route::post('/broadcasting/auth', BroadcastingAuthController::class)->middleware('can:identity.authenticated')->name('identity.broadcasting.auth');

        Route::middleware('permission:people.manage')->group(function (): void {
            Route::get('/users', [UsersController::class, 'index'])->name('identity.users.index');
            Route::post('/users', [UsersController::class, 'store'])->middleware('idempotency')->name('identity.users.store');
            Route::patch('/users/{id}', [UsersController::class, 'update'])->middleware('idempotency')->whereNumber('id')->name('identity.users.update');
            Route::post('/users/{id}/short-code/reset', [UsersController::class, 'resetShortCode'])->middleware('idempotency')->whereNumber('id')->name('identity.users.short-code.reset');
            Route::put('/users/{id}/stations', [UsersController::class, 'replaceStations'])->middleware('idempotency')->whereNumber('id')->name('identity.users.stations.replace');

            Route::get('/stations', [StationsController::class, 'index'])->name('identity.stations.index');
            Route::post('/stations', [StationsController::class, 'store'])->middleware('idempotency')->name('identity.stations.store');

            Route::get('/devices', [DevicesController::class, 'index'])->name('identity.devices.index');
            Route::post('/devices', [DevicesController::class, 'store'])->middleware('idempotency')->name('identity.devices.store');
            Route::post('/devices/{id}/revoke', [DevicesController::class, 'revoke'])->middleware('idempotency')->whereNumber('id')->name('identity.devices.revoke');
        });
    });
});
