<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FilesController;
use App\Shared\Files\Http\ServeFile;
use Illuminate\Support\Facades\Route;

/*
 * Shared\Files routes under /api/v1 (loaded by SharedServiceProvider).
 *
 * 1. The signed read (step 4.2a): the target of a signed URL for a file on the local disk (Files::signedUrl). No auth:sanctum, no actor, no
 *    permission: an <img> cannot send a bearer token, so the `signed` middleware is the protection. Only its GET is public:
 *    tests/Support/public_routes.php lists 'GET api/v1/files/{file}' and PUBLIC_ROUTES of scripts/check_invariants.py the same, because the
 *    DELETE shares the uri.
 * 2. The API (step 4.2b): bearer token, the actor middleware and a permission on every route, the two writes with idempotency (rule 7). The
 *    controller is App\Http\Controllers\Api\FilesController (Shared imports no module, and the writes are audited by Audit). `doctor` is
 *    declared before the numeric {file} routes, which accept digits only.
 */
Route::prefix('api/v1')->middleware(['api', 'signed'])->group(function (): void {
    Route::get('/files/{file}', ServeFile::class)->whereNumber('file')->name('files.show');
});

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::get('/files/doctor', [FilesController::class, 'doctor'])->middleware('permission:channels.manage')->name('files.doctor');
    Route::post('/files', [FilesController::class, 'store'])->middleware(['permission:files.upload', 'idempotency'])->name('files.store');
    Route::get('/files/{file}/url', [FilesController::class, 'url'])->whereNumber('file')->middleware('permission:payments.view|files.upload')->name('files.url');
    Route::delete('/files/{file}', [FilesController::class, 'destroy'])->whereNumber('file')->middleware(['permission:files.upload', 'idempotency'])->name('files.destroy');
});
