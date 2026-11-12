<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\BrandController;
use App\Modules\Identity\Http\Controllers\BroadcastingAuthController;
use App\Modules\Identity\Http\Controllers\DemoLoginController;
use App\Modules\Identity\Http\Controllers\DemoLoginSwitchController;
use App\Modules\Identity\Http\Controllers\DevicesController;
use App\Modules\Identity\Http\Controllers\ShortCodeController;
use App\Modules\Identity\Http\Controllers\StationsController;
use App\Modules\Identity\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

/*
 * Identity routes under /api/v1 (owned by App\Modules\Identity, loaded by IdentityServiceProvider; kept out of app/
 * so PSR-4 class discovery never executes it). Public: login and the pad's short-code gate, both throttled. Everything else: auth:sanctum, the actor middleware (branch + actor + device + acting session),
 * a permission (rule 6) and, on writes, idempotency (rule 7). The short-code gate is a station route (tests/Support/station_routes.php): it
 * carries the `device` middleware, which refuses an unenrolled pad once identity.require_enrolled_device is on (step 5.1a). Every route is listed in
 * tests/Support/route_permissions.php and every write in tests/Support/write_endpoints.php.
 */
Route::prefix('api/v1')->middleware('api')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('identity.login');
    // TEMPORARY (ADR 0025): the sign-in page's one-click demo buttons; both answer 404 unless the demo branch's admin switched them on
    // (PUT /brand/demo-login) and the server does not force them off (BAKERY_DEMO_LOGIN=false)
    Route::get('/demo-logins', [DemoLoginController::class, 'index'])->middleware('throttle:demo-logins')->name('identity.demo-logins.index');
    Route::post('/demo-login', [DemoLoginController::class, 'store'])->middleware('throttle:login')->name('identity.demo-login');
    // a station route (step 5.1a): the pad's lock screen answers only an enrolled pad once identity.require_enrolled_device is on
    Route::post('/short-code/verify', [ShortCodeController::class, 'verify'])->middleware(['throttle:short-code', 'device'])->name('identity.short-code.verify');

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
            Route::patch('/stations/{station}', [StationsController::class, 'update'])->middleware('idempotency')->whereNumber('station')->name('identity.stations.update');
        });

        // Devices (step 5.1a, phase-5 plan 4.1): devices.manage (the admin; people.manage until Phase 5), and GET /devices/this for every
        // signed-in role (devices.self), which is not a station route and never answers 403 for the device that asks.
        Route::middleware('permission:devices.manage')->group(function (): void {
            Route::get('/devices', [DevicesController::class, 'index'])->name('identity.devices.index');
            Route::post('/devices', [DevicesController::class, 'store'])->middleware('idempotency')->name('identity.devices.store');
            Route::post('/devices/{id}/rename', [DevicesController::class, 'rename'])->middleware('idempotency')->whereNumber('id')->name('identity.devices.rename');
            Route::post('/devices/{id}/revoke', [DevicesController::class, 'revoke'])->middleware('idempotency')->whereNumber('id')->name('identity.devices.revoke');
        });
        Route::get('/devices/this', [DevicesController::class, 'current'])->middleware('permission:devices.self')->name('identity.devices.this');

        // the business name and logo the app shows (Phase 6 follow-up): the admin sets them, everyone reads them in GET /me
        Route::middleware('permission:branding.manage')->group(function (): void {
            Route::put('/brand', [BrandController::class, 'update'])->middleware('idempotency')->name('identity.brand.update');
            Route::put('/brand/clock', [BrandController::class, 'updateClock'])->middleware('idempotency')->name('identity.brand.clock');
            Route::post('/brand/logo', [BrandController::class, 'storeLogo'])->middleware('idempotency')->name('identity.brand.logo.store');
            Route::delete('/brand/logo', [BrandController::class, 'destroyLogo'])->middleware('idempotency')->name('identity.brand.logo.destroy');
            // TEMPORARY (ADR 0025): the demo branch's admin switches the sign-in page's demo buttons on and off (Admin > System)
            Route::get('/brand/demo-login', [DemoLoginSwitchController::class, 'show'])->name('identity.brand.demo-login.show');
            Route::put('/brand/demo-login', [DemoLoginSwitchController::class, 'update'])->middleware('idempotency')->name('identity.brand.demo-login.update');
        });
    });

    // The logo's file (Phase 6 follow-up), public by design like a product photo's renditions: the sign-in page's <img> sends no bearer
    // token and a logo is not secret; the URL names the file's sha256 (cached as immutable). Listed in tests/Support/public_routes.php and
    // in PUBLIC_ROUTES of scripts/check_invariants.py. The pattern admits a 64-hex sha and the two extensions only.
    Route::get('/media/brand/{sha}.{ext}', [BrandController::class, 'media'])
        ->where(['sha' => '[0-9a-f]{64}', 'ext' => 'webp|jpg'])
        ->name('identity.brand.media');
});
