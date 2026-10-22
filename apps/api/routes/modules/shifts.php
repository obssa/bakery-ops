<?php

declare(strict_types=1);

use App\Modules\Shifts\Http\Controllers\ShiftsController;
use App\Modules\Shifts\Http\Controllers\StationShiftsController;
use App\Modules\Shifts\Http\Controllers\TemplatesController;
use Illuminate\Support\Facades\Route;

/*
 * Shifts routes under /api/v1 (owned by App\Modules\Shifts, loaded by ShiftsServiceProvider; kept out of app/ so PSR-4 class
 * discovery never executes it). Every route: auth:sanctum, the actor middleware (branch + actor), a permission (rule 6); every write
 * also carries idempotency (rule 7). {shift} and {template} bind through the BranchScoped models, so another branch's row is 404.
 * Every route is listed in tests/Support/route_permissions.php and every write in tests/Support/write_endpoints.php.
 *
 * Step 3.2b: shifts.view reads (the action checks the station: 403 shifts.station.forbidden), shifts.manage (the admin) configures
 * and schedules, shifts.count starts, joins and leaves (the action checks the station too). Cancel asks only for shifts.view because
 * who may cancel depends on the shift (the admin, or the person who started it while it is opening), which the action decides
 * (403 shifts.cancel.forbidden). Close, summary, counts, cash, notes, handover and replay come with the later steps.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::middleware('permission:shifts.view')->group(function (): void {
        Route::get('/shift-templates', [TemplatesController::class, 'index'])->name('shifts.templates.index');
        Route::get('/shifts', [ShiftsController::class, 'index'])->name('shifts.index');
        Route::get('/shifts/{shift}', [ShiftsController::class, 'show'])->whereNumber('shift')->name('shifts.show');
        Route::get('/stations/{station}/shifts/current', [StationShiftsController::class, 'current'])->whereNumber('station')->name('shifts.current');
    });

    Route::middleware(['permission:shifts.manage', 'idempotency'])->group(function (): void {
        Route::post('/shift-templates', [TemplatesController::class, 'store'])->name('shifts.templates.store');
        Route::patch('/shift-templates/{template}', [TemplatesController::class, 'update'])->whereNumber('template')->name('shifts.templates.update');
        Route::delete('/shift-templates/{template}', [TemplatesController::class, 'destroy'])->whereNumber('template')->name('shifts.templates.destroy');
        Route::post('/shifts', [ShiftsController::class, 'store'])->name('shifts.store');
        Route::put('/shifts/{shift}/roster', [ShiftsController::class, 'replaceRoster'])->whereNumber('shift')->name('shifts.roster');
    });

    Route::middleware(['permission:shifts.view', 'idempotency'])->group(function (): void {
        Route::post('/shifts/{shift}/cancel', [ShiftsController::class, 'cancel'])->whereNumber('shift')->name('shifts.cancel');
    });

    Route::middleware(['permission:shifts.count', 'idempotency'])->group(function (): void {
        Route::post('/stations/{station}/shifts/start', [StationShiftsController::class, 'start'])->whereNumber('station')->name('shifts.start');
        Route::post('/stations/{station}/shifts/open-ad-hoc', [StationShiftsController::class, 'openAdHoc'])->whereNumber('station')->name('shifts.open_ad_hoc');
        Route::post('/shifts/{shift}/join', [ShiftsController::class, 'join'])->whereNumber('shift')->name('shifts.join');
        Route::post('/shifts/{shift}/leave', [ShiftsController::class, 'leave'])->whereNumber('shift')->name('shifts.leave');
    });
});
