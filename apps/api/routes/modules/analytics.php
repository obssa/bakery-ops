<?php

declare(strict_types=1);

use App\Modules\Analytics\Export\Reports;
use App\Modules\Analytics\Http\Controllers\ChannelsController;
use App\Modules\Analytics\Http\Controllers\ExportsController;
use App\Modules\Analytics\Http\Controllers\FreshnessController;
use App\Modules\Analytics\Http\Controllers\ItemsController;
use App\Modules\Analytics\Http\Controllers\OverviewController;
use App\Modules\Analytics\Http\Controllers\RebuildController;
use App\Modules\Analytics\Http\Controllers\SalesController;
use App\Modules\Analytics\Http\Controllers\ShiftsController;
use App\Modules\Analytics\Http\Controllers\StaffController;
use App\Modules\Analytics\Http\Controllers\StationsController;
use App\Modules\Analytics\Http\Controllers\WeeklySummaryController;
use Illuminate\Support\Facades\Route;

/*
 * Analytics routes under /api/v1 (owned by App\Modules\Analytics, loaded by AnalyticsServiceProvider; phase-4 plan section 4.6). Every route:
 * auth:sanctum, the actor middleware (branch, actor, the acting-as session of a shared till, the device) and a permission (rule 6); the write
 * also carries idempotency (rule 7). Everything under /analytics reads the summary tables only. Step 4.4b: the freshness feed the dashboards
 * poll (analytics.view_own: every role) and the on-demand rebuild (analytics.manage: the admin). Step 4.4c: the dashboards (analytics.view:
 * admin and manager), stations and staff also with analytics.view_own, each limited by its controller to the caller's own stations or rows;
 * every one is conditional and reads the summaries only (DashboardsReadSummariesOnlyTest). Step 4.4d: the exports (analytics.view; one of the six
 * reports of Export\Reports, any other name is 404), read from the summaries too, and the weekly summary sent by hand (analytics.manage), and
 * the one public route of Analytics: GET /weekly-summaries/{week}, the PDF the weekly message's signed link opens (no bearer token; the
 * `signed` middleware is the whole protection, tests/Support/public_routes.php).
 * Every route is listed in tests/Support/route_permissions.php and every write in tests/Support/write_endpoints.php.
 */
Route::prefix('api/v1')->middleware(['api', 'signed'])->group(function (): void {
    Route::get('/weekly-summaries/{week}', [WeeklySummaryController::class, 'pdf'])->where('week', '[0-9]{4}-[0-9]{2}-[0-9]{2}')->name('analytics.weekly-summary.pdf');
});

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::get('/analytics/freshness', [FreshnessController::class, 'show'])->middleware('permission:analytics.view_own')->name('analytics.freshness');
    Route::post('/analytics/rebuild', [RebuildController::class, 'store'])->middleware(['permission:analytics.manage', 'idempotency'])->name('analytics.rebuild');
    Route::post('/analytics/weekly-summary/send', [WeeklySummaryController::class, 'send'])->middleware(['permission:analytics.manage', 'idempotency'])->name('analytics.weekly-summary.send');

    Route::get('/analytics/overview', [OverviewController::class, 'show'])->middleware('permission:analytics.view')->name('analytics.overview');
    Route::get('/analytics/sales', [SalesController::class, 'index'])->middleware('permission:analytics.view')->name('analytics.sales');
    Route::get('/analytics/sales/hourly', [SalesController::class, 'hourly'])->middleware('permission:analytics.view')->name('analytics.sales.hourly');
    Route::get('/analytics/channels', [ChannelsController::class, 'index'])->middleware('permission:analytics.view')->name('analytics.channels');
    Route::get('/analytics/items', [ItemsController::class, 'index'])->middleware('permission:analytics.view')->name('analytics.items');
    Route::get('/analytics/items/{item}', [ItemsController::class, 'show'])->whereNumber('item')->middleware('permission:analytics.view')->name('analytics.items.show');
    Route::get('/analytics/stations', [StationsController::class, 'index'])->middleware('permission:analytics.view|analytics.view_own')->name('analytics.stations');
    Route::get('/analytics/staff', [StaffController::class, 'index'])->middleware('permission:analytics.view|analytics.view_own')->name('analytics.staff');
    Route::get('/analytics/shifts', [ShiftsController::class, 'index'])->middleware('permission:analytics.view')->name('analytics.shifts');
    Route::get('/analytics/exports/{report}', [ExportsController::class, 'show'])->whereIn('report', Reports::NAMES)->middleware('permission:analytics.view')->name('analytics.exports');
});
