<?php

declare(strict_types=1);

use App\Modules\Analytics\Http\Controllers\FreshnessController;
use App\Modules\Analytics\Http\Controllers\RebuildController;
use Illuminate\Support\Facades\Route;

/*
 * Analytics routes under /api/v1 (owned by App\Modules\Analytics, loaded by AnalyticsServiceProvider; phase-4 plan section 4.6). Every route:
 * auth:sanctum, the actor middleware (branch, actor, the acting-as session of a shared till, the device) and a permission (rule 6); the write
 * also carries idempotency (rule 7). Everything under /analytics reads the summary tables only. Step 4.4b: the freshness feed the dashboards
 * poll (analytics.view_own: every role) and the on-demand rebuild (analytics.manage: the admin). The dashboards are step 4.4c, the exports and
 * the weekly summary 4.4d. Every route is listed in tests/Support/route_permissions.php and every write in tests/Support/write_endpoints.php.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::get('/analytics/freshness', [FreshnessController::class, 'show'])->middleware('permission:analytics.view_own')->name('analytics.freshness');
    Route::post('/analytics/rebuild', [RebuildController::class, 'store'])->middleware(['permission:analytics.manage', 'idempotency'])->name('analytics.rebuild');
});
