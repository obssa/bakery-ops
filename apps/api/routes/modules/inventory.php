<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\LedgerController;
use App\Modules\Inventory\Http\Controllers\LocationsController;
use App\Modules\Inventory\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

/*
 * Inventory routes under /api/v1 (owned by App\Modules\Inventory, loaded by InventoryServiceProvider; kept out of app/
 * so PSR-4 class discovery never executes it). Every route: auth:sanctum, the actor middleware (branch + actor), a
 * permission (rule 6): inventory.view (the floor roles, not the waiter) to read stock, locations and the ledger,
 * inventory.manage (admin) to configure locations, inventory.adjust (admin) for the Phase 0 manual movements; every
 * write also carries idempotency (rule 7). {location} binds through the BranchScoped model, so another branch's row is
 * 404. Every route is listed in tests/Support/route_permissions.php and every write in tests/Support/write_endpoints.php.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::middleware('permission:inventory.view')->group(function (): void {
        Route::get('/locations', [LocationsController::class, 'index'])->name('inventory.locations.index');
        Route::get('/stock', [StockController::class, 'index'])->name('inventory.stock.index');
        Route::get('/ledger', [LedgerController::class, 'index'])->name('inventory.ledger.index');
    });

    Route::middleware(['permission:inventory.manage', 'idempotency'])->group(function (): void {
        Route::post('/locations', [LocationsController::class, 'store'])->name('inventory.locations.store');
        Route::patch('/locations/{location}', [LocationsController::class, 'update'])->whereNumber('location')->name('inventory.locations.update');
    });

    Route::middleware(['permission:inventory.adjust', 'idempotency'])->group(function (): void {
        Route::post('/stock/movements', [StockController::class, 'storeMovement'])->name('inventory.stock.movements.store');
    });
});
