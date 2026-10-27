<?php

declare(strict_types=1);

use App\Modules\Audit\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

/*
 * Audit routes under /api/v1 (owned by App\Modules\Audit, loaded by AuditServiceProvider; phase-4 plan section 4.7, step 4.5a). Every route:
 * auth:sanctum, the actor middleware (branch, actor, the acting-as session of a shared till, the device) and a permission (rule 6). Reads
 * only, so no idempotency (the export writes its own audit row, as every export does). The export is declared before {id}, which takes
 * digits only. Every route is listed in tests/Support/route_permissions.php.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::get('/audit-log', [AuditLogController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');
    Route::get('/audit-log/export', [AuditLogController::class, 'export'])->middleware('permission:audit.export')->name('audit.export');
    Route::get('/audit-log/{id}', [AuditLogController::class, 'show'])->whereNumber('id')->middleware('permission:audit.view')->name('audit.show');
});
