<?php

declare(strict_types=1);

use App\Shared\Files\Http\ServeFile;
use Illuminate\Support\Facades\Route;

/*
 * The one route Shared\Files owns in step 4.2a: the target of a signed URL for a file on the local disk (Files::signedUrl). No auth:sanctum,
 * no actor, no permission: an <img> cannot send a bearer token, so the `signed` middleware is the protection (listed in
 * tests/Support/public_routes.php and PUBLIC_ROUTES of scripts/check_invariants.py). A read, so no Idempotency-Key. Step 4.2b adds
 * POST /files, GET /files/{file}/url, DELETE /files/{file} and GET /files/doctor around it.
 */
Route::prefix('api/v1')->middleware(['api', 'signed'])->group(function (): void {
    Route::get('/files/{file}', ServeFile::class)->whereNumber('file')->name('files.show');
});
