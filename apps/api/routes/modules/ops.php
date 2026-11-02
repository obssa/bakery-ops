<?php

declare(strict_types=1);

use App\Ops\Http\SystemStatusController;
use Illuminate\Support\Facades\Route;

/*
 * App\Ops routes under /api/v1 (loaded by OpsServiceProvider; phase-5 plan 4.4, step 5.5c). One read: the System page's status, for the
 * holders of system.view (the admin). An admin screen, not a station route (tests/Support/non_station_routes.php). No route starts a backup,
 * a drill or a restore: those are console commands the scheduler runs (and the runbook by hand).
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::get('/system/status', [SystemStatusController::class, 'show'])->middleware('permission:system.view')->name('ops.system.status');
});
