<?php

declare(strict_types=1);

use App\Modules\Shifts\Http\Controllers\ChainController;
use App\Modules\Shifts\Http\Controllers\CountsController;
use App\Modules\Shifts\Http\Controllers\HandoverController;
use App\Modules\Shifts\Http\Controllers\NotesController;
use App\Modules\Shifts\Http\Controllers\ShiftsController;
use App\Modules\Shifts\Http\Controllers\StationShiftsController;
use App\Modules\Shifts\Http\Controllers\TemplatesController;
use App\Modules\Shifts\Http\Controllers\VarianceReasonsController;
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
 * (403 shifts.cancel.forbidden). Step 3.3a adds the counts: shifts.view reads the summary, the counts and the variance reasons (the
 * action checks the station), shifts.count closes the shift and works a count (it checks the station too), shifts.manage keeps the
 * variance reasons. Step 3.3b adds approve and reject (shifts.approve); step 3.3c adds cosign and the queue, and the reject moves to shifts.view with the action deciding. {count} and {reason} bind through the BranchScoped models. Step 3.5 adds the cash: shifts.count declares the drawer's cash (the action checks the till), shifts.view reads the shift's cash. Step 3.6 adds the notes (shifts.view reads, shifts.count writes; the action checks the station), the handover panel (shifts.view at the station) and the replay (shifts.replay).
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::middleware('permission:shifts.view')->group(function (): void {
        Route::get('/shift-templates', [TemplatesController::class, 'index'])->name('shifts.templates.index');
        Route::get('/shifts', [ShiftsController::class, 'index'])->name('shifts.index');
        Route::get('/shifts/{shift}', [ShiftsController::class, 'show'])->whereNumber('shift')->middleware('device')->name('shifts.show');
        Route::get('/stations/{station}/shifts/current', [StationShiftsController::class, 'current'])->whereNumber('station')->middleware('device')->name('shifts.current');
        Route::get('/shifts/{shift}/summary', [ShiftsController::class, 'summary'])->whereNumber('shift')->name('shifts.summary');
        Route::get('/shifts/{shift}/cash', [ShiftsController::class, 'cash'])->whereNumber('shift')->middleware('device')->name('shifts.cash');
        Route::get('/shifts/{shift}/counts', [CountsController::class, 'forShift'])->whereNumber('shift')->middleware('device')->name('shifts.counts.index');
        Route::get('/stock-counts', [CountsController::class, 'index'])->name('shifts.counts.queue');
        Route::get('/stock-counts/{count}', [CountsController::class, 'show'])->whereNumber('count')->middleware('device')->name('shifts.counts.show');
        Route::get('/variance-reasons', [VarianceReasonsController::class, 'index'])->middleware('device')->name('shifts.reasons.index');
        Route::get('/shifts/{shift}/notes', [NotesController::class, 'index'])->whereNumber('shift')->middleware('device')->name('shifts.notes.index');
        Route::get('/stations/{station}/handover', [HandoverController::class, 'show'])->whereNumber('station')->middleware('device')->name('shifts.handover');
    });

    Route::middleware(['permission:shifts.manage', 'idempotency'])->group(function (): void {
        Route::post('/shift-templates', [TemplatesController::class, 'store'])->name('shifts.templates.store');
        Route::patch('/shift-templates/{template}', [TemplatesController::class, 'update'])->whereNumber('template')->name('shifts.templates.update');
        Route::delete('/shift-templates/{template}', [TemplatesController::class, 'destroy'])->whereNumber('template')->name('shifts.templates.destroy');
        Route::post('/shifts', [ShiftsController::class, 'store'])->name('shifts.store');
        Route::put('/shifts/{shift}/roster', [ShiftsController::class, 'replaceRoster'])->whereNumber('shift')->name('shifts.roster');
        Route::post('/variance-reasons', [VarianceReasonsController::class, 'store'])->name('shifts.reasons.store');
        Route::patch('/variance-reasons/{reason}', [VarianceReasonsController::class, 'update'])->whereNumber('reason')->name('shifts.reasons.update');
    });

    Route::middleware(['permission:shifts.view', 'idempotency'])->group(function (): void {
        Route::post('/shifts/{shift}/cancel', [ShiftsController::class, 'cancel'])->whereNumber('shift')->middleware('device')->name('shifts.cancel');
    });

    Route::middleware(['permission:shifts.count', 'idempotency'])->group(function (): void {
        Route::post('/stations/{station}/shifts/start', [StationShiftsController::class, 'start'])->whereNumber('station')->middleware('device')->name('shifts.start');
        Route::post('/stations/{station}/shifts/open-ad-hoc', [StationShiftsController::class, 'openAdHoc'])->whereNumber('station')->middleware('device')->name('shifts.open_ad_hoc');
        Route::post('/shifts/{shift}/join', [ShiftsController::class, 'join'])->whereNumber('shift')->middleware('device')->name('shifts.join');
        Route::post('/shifts/{shift}/leave', [ShiftsController::class, 'leave'])->whereNumber('shift')->middleware('device')->name('shifts.leave');
        Route::post('/shifts/{shift}/close', [ShiftsController::class, 'close'])->whereNumber('shift')->middleware('device')->name('shifts.close');
        Route::put('/stock-counts/{count}/lines', [CountsController::class, 'lines'])->whereNumber('count')->middleware('device')->name('shifts.counts.lines');
        Route::put('/stock-counts/{count}/cash', [CountsController::class, 'cash'])->whereNumber('count')->middleware('device')->name('shifts.counts.cash');
        Route::post('/shifts/{shift}/notes', [NotesController::class, 'store'])->whereNumber('shift')->middleware('device')->name('shifts.notes.store');
        Route::post('/stock-counts/{count}/submit', [CountsController::class, 'submit'])->whereNumber('count')->middleware('device')->name('shifts.counts.submit');
    });

    // Step 3.3b: shifts.approve (admin, manager) approves a count; that the person who counted cannot approve it is the action's rule
    Route::middleware(['permission:shifts.approve', 'idempotency'])->group(function (): void {
        Route::post('/stock-counts/{count}/approve', [CountsController::class, 'approve'])->whereNumber('count')->name('shifts.counts.approve');
    });

    // Step 3.3c: co-signing is the overseer's of the station or a shifts.cosign holder's, and the reject belongs to the co-signer while a
    // count waits for a co-signature and to shifts.approve while it waits for a decision, so both ask only for shifts.view here and the
    // action decides (403 shifts.count.cosign_forbidden / auth.forbidden)
    Route::middleware(['permission:shifts.view', 'idempotency'])->group(function (): void {
        Route::post('/stock-counts/{count}/cosign', [CountsController::class, 'cosign'])->whereNumber('count')->middleware('device')->name('shifts.counts.cosign');
        Route::post('/stock-counts/{count}/reject', [CountsController::class, 'reject'])->whereNumber('count')->middleware('device')->name('shifts.counts.reject');
    });

    // Step 3.4: the unlock is the admin's, and the chain of a place is read and verified by the people who may replay (admin, manager)
    Route::middleware(['permission:shifts.unlock', 'idempotency'])->group(function (): void {
        Route::post('/stock-counts/{count}/unlock', [CountsController::class, 'unlock'])->whereNumber('count')->name('shifts.counts.unlock');
    });

    Route::middleware('permission:shifts.replay')->group(function (): void {
        Route::get('/locations/{location}/chain', [ChainController::class, 'index'])->whereNumber('location')->name('shifts.chain.index');
        Route::get('/locations/{location}/chain/verify', [ChainController::class, 'verify'])->whereNumber('location')->name('shifts.chain.verify');
        Route::get('/replay', [HandoverController::class, 'replay'])->name('shifts.replay');
    });
});
