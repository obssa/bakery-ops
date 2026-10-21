<?php

declare(strict_types=1);

use App\Modules\Production\Http\Controllers\BatchesController;
use App\Modules\Production\Http\Controllers\RequestsController;
use Illuminate\Support\Facades\Route;

/*
 * Production routes under /api/v1 (owned by App\Modules\Production, loaded by ProductionServiceProvider; kept out of app/ so
 * PSR-4 class discovery never executes it). Every route: auth:sanctum, the actor middleware (branch + actor), a permission
 * (rule 6); every write also carries idempotency (rule 7). {request} binds through the BranchScoped model, so another branch's
 * row is 404. Every route is listed in tests/Support/route_permissions.php and every write in tests/Support/write_endpoints.php.
 *
 * Step 2.7b adds the requests: the floor reads the board and a card (production.view, polled with an ETag); production.request
 * asks, production.plan assigns, production.fulfil accepts (the action also checks the baker works at the request's station);
 * cancel asks only for production.view because it is the planner's or the requester's own, which the action decides.
 *
 * Step 2.7c adds the batches: production.fulfil starts (the request's assignee, at its station) and presses Done; confirm and
 * send-back ask only for production.view because who may sign off depends on the station (production.confirm, or the overseer
 * flagged there), which the action decides (403 production.confirm.forbidden). {batch} binds through the BranchScoped model.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::middleware('permission:production.view')->group(function (): void {
        Route::get('/production/requests', [RequestsController::class, 'index'])->name('production.requests.index');
        Route::get('/production/requests/{request}', [RequestsController::class, 'show'])->whereNumber('request')->name('production.requests.show');
    });

    Route::middleware(['permission:production.request', 'idempotency'])->group(function (): void {
        Route::post('/production/requests', [RequestsController::class, 'store'])->name('production.requests.store');
    });

    Route::middleware(['permission:production.plan', 'idempotency'])->group(function (): void {
        Route::post('/production/requests/{request}/assign', [RequestsController::class, 'assign'])->whereNumber('request')->name('production.requests.assign');
    });

    Route::middleware(['permission:production.fulfil', 'idempotency'])->group(function (): void {
        Route::post('/production/requests/{request}/accept', [RequestsController::class, 'accept'])->whereNumber('request')->name('production.requests.accept');
        Route::post('/production/requests/{request}/start', [BatchesController::class, 'start'])->whereNumber('request')->name('production.requests.start');
        Route::post('/production/batches/{batch}/done', [BatchesController::class, 'done'])->whereNumber('batch')->name('production.batches.done');
    });

    Route::middleware(['permission:production.view', 'idempotency'])->group(function (): void {
        Route::post('/production/requests/{request}/cancel', [RequestsController::class, 'cancel'])->whereNumber('request')->name('production.requests.cancel');
        Route::post('/production/batches/{batch}/confirm', [BatchesController::class, 'confirm'])->whereNumber('batch')->name('production.batches.confirm');
        Route::post('/production/batches/{batch}/send-back', [BatchesController::class, 'sendBack'])->whereNumber('batch')->name('production.batches.send_back');
    });
});
