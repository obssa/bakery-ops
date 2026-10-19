<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Contracts\Shortfall;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Shared\Models\Branch;
use App\Shared\Values\Quantity;
use Illuminate\Support\Str;

/*
 * Availability (dependency-graph.md, cycle 6; domain-model.md, Catalog): how many units of a product a pad may sell
 * at a location, branching on items.fulfilment. to_order = floor(min over the required recipe lines of
 * available(ingredient) / quantity per unit); to_stock and bought_in = available of the product itself. Reserved stock
 * is not available. Answers are decimal quantities in the product's base unit, never below zero.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
    $b = $this->branch->id;
    $this->kitchen = stockLocation($this->branch, LocationType::Kitchen);
    $this->display = stockLocation($this->branch, LocationType::DisplayCase);

    $this->cheese = Item::factory()->ingredient('g')->create(['branch_id' => $b]);
    $this->flour = Item::factory()->ingredient('kg')->create(['branch_id' => $b]);
    $this->basil = Item::factory()->ingredient('g')->create(['branch_id' => $b]);
    $this->pizza = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b]);
    $this->cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $b]);
    $this->water = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $b]);
    $this->latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b]);

    // a pizza tray yields 4 pieces from 300 g cheese and 0.5 kg flour (2 % waste); basil is optional
    $recipe = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $this->pizza->id, 'yield_qty' => '4', 'yield_unit_id' => catalogUnit('pcs')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $this->cheese->id, 'qty' => '300', 'unit_id' => catalogUnit('g')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $this->flour->id, 'qty' => '0.5', 'unit_id' => catalogUnit('kg')->id, 'waste_pct' => '2.00']);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $this->basil->id, 'qty' => '5', 'unit_id' => catalogUnit('g')->id, 'is_optional' => true]);

    $this->availability = app(Availability::class);
});

it('computes a to-order product as floor(min(available ingredient / qty per unit))', function () {
    // per piece: cheese 75 g, flour 127.5 g (500 g x 1.02 / 4)
    stockIn($this->cheese, $this->kitchen, '1000');   // 13.33 pieces
    stockIn($this->flour, $this->kitchen, '1300');    // 10.19 pieces

    expect($this->availability->ofProduct($this->pizza, $this->kitchen))->toBeInstanceOf(Quantity::class)
        ->and($this->availability->ofProduct($this->pizza, $this->kitchen)->toString())->toBe('10.0000');

    stockIn($this->flour, $this->kitchen, '700');     // 2000 g: 15.68 pieces, cheese now limits at 13
    expect($this->availability->ofProduct($this->pizza->id, $this->kitchen->id)->toString())->toBe('13.0000');

    // reserved stock is not available: 3 pizzas reserved leave 10
    app(Reservations::class)->reserve([new ReserveLine($this->pizza->id, '3', $this->kitchen->id)], (string) Str::uuid());
    expect($this->availability->ofProduct($this->pizza, $this->kitchen)->toString())->toBe('10.0000');

    // the stock is per location: none of it is in the display case
    expect($this->availability->ofProduct($this->pizza, $this->display)->toString())->toBe('0.0000');
})->group('module:inventory', 'feature');

it('ignores optional lines and answers zero for a to-order product without a recipe or without stock', function () {
    stockIn($this->cheese, $this->kitchen, '75');
    stockIn($this->flour, $this->kitchen, '127.5');

    expect($this->availability->ofProduct($this->pizza, $this->kitchen)->toString())->toBe('1.0000') // no basil, still one
        ->and($this->availability->ofProduct($this->latte, $this->kitchen)->toString())->toBe('0.0000');

    stockIn($this->flour, $this->kitchen, '-127.5', MovementReason::Waste);
    expect($this->availability->ofProduct($this->pizza, $this->kitchen)->toString())->toBe('0.0000');
})->group('module:inventory', 'feature');

it('computes a to-stock or bought-in product as its own available stock at the location', function () {
    stockIn($this->cake, $this->display, '6');
    stockIn($this->water, $this->display, '24');
    app(Reservations::class)->reserve([new ReserveLine($this->cake->id, '2', $this->display->id)], (string) Str::uuid());

    expect($this->availability->ofProduct($this->cake, $this->display)->toString())->toBe('4.0000')
        ->and($this->availability->ofProduct($this->water, $this->display)->toString())->toBe('24.0000')
        ->and($this->availability->ofProduct($this->cake, $this->kitchen)->toString())->toBe('0.0000');

    // stock wasted while reserved leaves nothing to sell, never a negative number
    stockIn($this->cake, $this->display, '-5', MovementReason::Waste);
    expect($this->availability->ofProduct($this->cake, $this->display)->toString())->toBe('0.0000');
})->group('module:inventory', 'feature');

it('answers bulk for many products in one call, matching ofProduct for each', function () {
    stockIn($this->cheese, $this->kitchen, '1000');
    stockIn($this->flour, $this->kitchen, '1300');
    stockIn($this->cake, $this->kitchen, '2');
    stockIn($this->water, $this->kitchen, '7.5');

    $bulk = $this->availability->bulk([$this->pizza, $this->cake->id, $this->water, $this->latte], $this->kitchen);

    expect(array_keys($bulk))->toEqualCanonicalizing([$this->pizza->id, $this->cake->id, $this->water->id, $this->latte->id])
        ->and($bulk[$this->pizza->id]->toString())->toBe('10.0000')
        ->and($bulk[$this->cake->id]->toString())->toBe('2.0000')
        ->and($bulk[$this->water->id]->toString())->toBe('7.5000')
        ->and($bulk[$this->latte->id]->toString())->toBe('0.0000');
    foreach ([$this->pizza, $this->cake, $this->water, $this->latte] as $product) {
        expect($bulk[$product->id]->toString())->toBe($this->availability->ofProduct($product, $this->kitchen)->toString());
    }
    expect($this->availability->bulk([], $this->kitchen))->toBe([]);
})->group('module:inventory', 'feature');

it('agrees with reserve: exactly the available number of units can be reserved, one more is short', function () {
    stockIn($this->cheese, $this->kitchen, '1000');
    stockIn($this->flour, $this->kitchen, '1300');
    $n = $this->availability->ofProduct($this->pizza, $this->kitchen)->toString();
    expect($n)->toBe('10.0000');

    $reservations = app(Reservations::class);
    expect($reservations->reserve([new ReserveLine($this->pizza->id, '1', $this->kitchen->id)], (string) Str::uuid()))->not->toBeInstanceOf(Shortfall::class)
        ->and($reservations->reserve([new ReserveLine($this->pizza->id, '9', $this->kitchen->id)], (string) Str::uuid()))->not->toBeInstanceOf(Shortfall::class)
        ->and($reservations->reserve([new ReserveLine($this->pizza->id, '1', $this->kitchen->id)], (string) Str::uuid()))->toBeInstanceOf(Shortfall::class)
        ->and($this->availability->ofProduct($this->pizza, $this->kitchen)->toString())->toBe('0.0000');
})->group('module:inventory', 'feature');
