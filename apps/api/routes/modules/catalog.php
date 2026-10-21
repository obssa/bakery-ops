<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\CategoriesController;
use App\Modules\Catalog\Http\Controllers\ItemPricesController;
use App\Modules\Catalog\Http\Controllers\ItemsController;
use App\Modules\Catalog\Http\Controllers\ItemStationsController;
use App\Modules\Catalog\Http\Controllers\ModifiersController;
use App\Modules\Catalog\Http\Controllers\RecipesController;
use App\Modules\Catalog\Http\Controllers\RecipeVersionsController;
use App\Modules\Catalog\Http\Controllers\UnitsController;
use Illuminate\Support\Facades\Route;

/*
 * Catalog routes under /api/v1 (owned by App\Modules\Catalog, loaded by CatalogServiceProvider; kept out of app/ so
 * PSR-4 class discovery never executes it). Every route: auth:sanctum, the actor middleware (branch + actor), a
 * permission (rule 6): catalog.view (every role) to read, catalog.manage (admin) to write; every write also carries
 * idempotency (rule 7). {item} and {category} bind through the BranchScoped models, so another branch's row is 404.
 * Every route is listed in tests/Support/route_permissions.php and every write in tests/Support/write_endpoints.php.
 */
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', 'actor'])->group(function (): void {
    Route::middleware('permission:catalog.view')->group(function (): void {
        Route::get('/units', [UnitsController::class, 'index'])->name('catalog.units.index');
        Route::get('/categories', [CategoriesController::class, 'index'])->name('catalog.categories.index');
        Route::get('/items', [ItemsController::class, 'index'])->name('catalog.items.index');
        Route::get('/items/{item}', [ItemsController::class, 'show'])->whereNumber('item')->name('catalog.items.show');
        Route::get('/items/{item}/prices', [ItemPricesController::class, 'index'])->whereNumber('item')->name('catalog.items.prices.index');
        Route::get('/items/{item}/recipe', [RecipesController::class, 'show'])->whereNumber('item')->name('catalog.items.recipe.show');
        Route::get('/items/{item}/recipe/versions', [RecipeVersionsController::class, 'index'])->whereNumber('item')->name('catalog.items.recipe.versions.index');
        Route::get('/items/{item}/recipe/versions/{version}', [RecipeVersionsController::class, 'show'])->whereNumber(['item', 'version'])->name('catalog.items.recipe.versions.show');
        Route::get('/items/{item}/modifiers', [ModifiersController::class, 'index'])->whereNumber('item')->name('catalog.items.modifiers.index');
    });

    Route::middleware(['permission:catalog.manage', 'idempotency'])->group(function (): void {
        Route::post('/units', [UnitsController::class, 'store'])->name('catalog.units.store');
        Route::post('/categories', [CategoriesController::class, 'store'])->name('catalog.categories.store');
        Route::patch('/categories/{category}', [CategoriesController::class, 'update'])->whereNumber('category')->name('catalog.categories.update');
        Route::delete('/categories/{category}', [CategoriesController::class, 'destroy'])->whereNumber('category')->name('catalog.categories.destroy');
        Route::post('/items', [ItemsController::class, 'store'])->name('catalog.items.store');
        Route::patch('/items/{item}', [ItemsController::class, 'update'])->whereNumber('item')->name('catalog.items.update');
        Route::delete('/items/{item}', [ItemsController::class, 'destroy'])->whereNumber('item')->name('catalog.items.destroy');
        Route::post('/items/{item}/prices', [ItemPricesController::class, 'store'])->whereNumber('item')->name('catalog.items.prices.store');
        Route::put('/items/{item}/recipe', [RecipesController::class, 'update'])->whereNumber('item')->name('catalog.items.recipe.update');
        Route::post('/items/{item}/recipe/versions', [RecipeVersionsController::class, 'store'])->whereNumber('item')->name('catalog.items.recipe.versions.store');
        Route::put('/items/{item}/stations', [ItemStationsController::class, 'update'])->whereNumber('item')->name('catalog.items.stations.update');
        Route::post('/items/{item}/modifiers', [ModifiersController::class, 'store'])->whereNumber('item')->name('catalog.items.modifiers.store');
    });
});
