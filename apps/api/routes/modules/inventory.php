<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\CostsController;
use App\Modules\Inventory\Http\Controllers\LedgerController;
use App\Modules\Inventory\Http\Controllers\LocationsController;
use App\Modules\Inventory\Http\Controllers\PurchasesController;
use App\Modules\Inventory\Http\Controllers\RecipeCostsController;
use App\Modules\Inventory\Http\Controllers\ReorderRulesController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockViewsController;
use App\Modules\Inventory\Http\Controllers\SuppliersController;
use App\Modules\Inventory\Http\Controllers\TransfersController;
use Illuminate\Support\Facades\Route;

/*
 * Inventory routes under /api/v1 (owned by App\Modules\Inventory, loaded by InventoryServiceProvider; kept out of app/
 * so PSR-4 class discovery never executes it). Every route: auth:sanctum, the actor middleware (branch + actor), a
 * permission (rule 6): inventory.view (the floor roles, not the waiter) to read stock, locations and the ledger,
 * inventory.manage (admin) to configure locations, inventory.adjust (admin) for the Phase 0 manual movements; every
 * write also carries idempotency (rule 7). {location} binds through the BranchScoped model, so another branch's row is
 * 404. Every route is listed in tests/Support/route_permissions.php and every write in tests/Support/write_endpoints.php.
 *
 * Step 2.2b adds suppliers and purchases (purchases.record: admin, manager) and the cost reads (costs.view: admin, manager);
 * the opening cost is an adjustment of the books (inventory.adjust).
 *
 * Step 2.3 adds transfers: the floor reads them (inventory.view), asks for stock (transfers.request) and the people who hold
 * transfers.confirm decide; the actions also check the person works at the destination's / source's station.
 *
 * Step 2.4b adds the recipe cost and margin read (costs.view): GET /inventory/recipe-costs/{product}.
 *
 * Step 2.6a adds reorder rules: the floor reads them (inventory.view); inventory.manage (admin) creates, changes and deletes them.
 *
 * Step 2.6b adds the low-stock reads (inventory.view, no key): the reorder list, the stock alerts, a station's stock strip (polled, ETag) and
 * the till's display-case panel (polled, ETag). The strip also checks the station (403 inventory.station.forbidden).
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::middleware('permission:inventory.view')->group(function (): void {
        Route::get('/locations', [LocationsController::class, 'index'])->name('inventory.locations.index');
        Route::get('/stock', [StockController::class, 'index'])->name('inventory.stock.index');
        Route::get('/ledger', [LedgerController::class, 'index'])->name('inventory.ledger.index');
        Route::get('/transfers', [TransfersController::class, 'index'])->name('inventory.transfers.index');
        Route::get('/reorder-rules', [ReorderRulesController::class, 'index'])->name('inventory.reorder-rules.index');
        Route::get('/reorder-list', [StockViewsController::class, 'reorderList'])->name('inventory.reorder-list');
        Route::get('/stock-alerts', [StockViewsController::class, 'alerts'])->name('inventory.stock-alerts');
        Route::get('/stations/{station}/stock-strip', [StockViewsController::class, 'strip'])->whereNumber('station')->name('inventory.stations.stock-strip');
        Route::get('/display-case', [StockViewsController::class, 'displayCase'])->name('inventory.display-case');
    });

    Route::middleware(['permission:inventory.manage', 'idempotency'])->group(function (): void {
        Route::post('/locations', [LocationsController::class, 'store'])->name('inventory.locations.store');
        Route::patch('/locations/{location}', [LocationsController::class, 'update'])->whereNumber('location')->name('inventory.locations.update');
        Route::post('/reorder-rules', [ReorderRulesController::class, 'store'])->name('inventory.reorder-rules.store');
        Route::patch('/reorder-rules/{rule}', [ReorderRulesController::class, 'update'])->whereNumber('rule')->name('inventory.reorder-rules.update');
        Route::delete('/reorder-rules/{rule}', [ReorderRulesController::class, 'destroy'])->whereNumber('rule')->name('inventory.reorder-rules.destroy');
    });

    Route::middleware(['permission:inventory.adjust', 'idempotency'])->group(function (): void {
        Route::post('/stock/movements', [StockController::class, 'storeMovement'])->name('inventory.stock.movements.store');
        Route::post('/inventory/costs/{item}/opening', [CostsController::class, 'opening'])->whereNumber('item')->name('inventory.costs.opening');
    });

    Route::middleware('permission:purchases.record')->group(function (): void {
        Route::get('/suppliers', [SuppliersController::class, 'index'])->name('inventory.suppliers.index');
        Route::get('/purchases', [PurchasesController::class, 'index'])->name('inventory.purchases.index');
        Route::get('/purchases/{purchase}', [PurchasesController::class, 'show'])->whereNumber('purchase')->name('inventory.purchases.show');
    });

    Route::middleware(['permission:purchases.record', 'idempotency'])->group(function (): void {
        Route::post('/suppliers', [SuppliersController::class, 'store'])->name('inventory.suppliers.store');
        Route::patch('/suppliers/{supplier}', [SuppliersController::class, 'update'])->whereNumber('supplier')->name('inventory.suppliers.update');
        Route::post('/purchases', [PurchasesController::class, 'store'])->name('inventory.purchases.store');
        Route::put('/purchases/{purchase}/lines', [PurchasesController::class, 'replaceLines'])->whereNumber('purchase')->name('inventory.purchases.lines');
        Route::post('/purchases/{purchase}/confirm', [PurchasesController::class, 'confirm'])->whereNumber('purchase')->name('inventory.purchases.confirm');
        Route::post('/purchases/{purchase}/cancel', [PurchasesController::class, 'cancel'])->whereNumber('purchase')->name('inventory.purchases.cancel');
    });

    Route::middleware(['permission:transfers.request', 'idempotency'])->group(function (): void {
        Route::post('/transfers', [TransfersController::class, 'store'])->name('inventory.transfers.store');
    });

    Route::middleware(['permission:transfers.confirm', 'idempotency'])->group(function (): void {
        Route::post('/transfers/{transfer}/confirm', [TransfersController::class, 'confirm'])->whereNumber('transfer')->name('inventory.transfers.confirm');
        Route::post('/transfers/{transfer}/reject', [TransfersController::class, 'reject'])->whereNumber('transfer')->name('inventory.transfers.reject');
    });

    Route::middleware('permission:costs.view')->group(function (): void {
        Route::get('/inventory/costs', [CostsController::class, 'index'])->name('inventory.costs.index');
        Route::get('/inventory/costs/{item}', [CostsController::class, 'show'])->whereNumber('item')->name('inventory.costs.show');
        Route::get('/inventory/recipe-costs/{product}', [RecipeCostsController::class, 'show'])->whereNumber('product')->name('inventory.recipe-costs.show');
    });
});
