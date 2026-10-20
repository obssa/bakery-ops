<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\Modifiers;
use App\Modules\Catalog\Contracts\ModifierView;
use App\Modules\Catalog\Contracts\Prices;
use App\Modules\Catalog\Contracts\RecipeDeltaLine;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Services\ItemStations;
use App\Modules\Catalog\Services\PriceHistory;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;

/*
 * Contract: orders -> catalog (manifest contracts["orders->catalog"]).
 *
 * Written BEFORE any code exists in App\Modules\Orders (step 1.1): only Catalog classes and factories. Phrased from
 * Orders' side: "when I, Orders, put a product on an order line, I rely on Catalog for the price in force when the
 * order was sent, the station the line goes to (none for the display case), the modifiers with their price and recipe
 * deltas, the recipe version I snapshot, and whether the product is new". It runs in Catalog's CI job (mod-catalog).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    withActor(makeUser($this->branch, 'admin')->id);
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $b]);
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);

    $this->sandwich = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b, 'name_en' => 'Chicken sandwich']);
    $this->latte = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b, 'name_en' => 'Latte']);
    $this->cake = Item::factory()->product(Fulfilment::ToStock, 'slice')->create(['branch_id' => $b, 'name_en' => 'Black forest cake (slice)', 'production_station_id' => $this->cakeRoom->id]);
    $this->water = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $b, 'name_en' => 'Bottled water']);
    $this->chicken = Item::factory()->ingredient('kg')->create(['branch_id' => $b, 'name_en' => 'Chicken breast']);
    $this->onion = Item::factory()->ingredient('kg')->create(['branch_id' => $b, 'name_en' => 'Onion']);
    $this->milk = Item::factory()->ingredient('L')->create(['branch_id' => $b, 'name_en' => 'Milk']);

    // routing as the admin sets it (PUT /items/{item}/stations)
    app(ItemStations::class)->replace($this->sandwich, [$this->kitchen->id]);
    app(ItemStations::class)->replace($this->latte, [$this->bar->id]);
});

it('answers Prices::at(sent_at) with the price in force when the order was sent, not the price now', function () {
    $prices = app(Prices::class);
    $history = app(PriceHistory::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-21 05:00:00', 'UTC'));
    $history->open($this->sandwich, Money::of('400.00'), CarbonImmutable::now());
    $sentAt = CarbonImmutable::parse('2026-10-21 12:15:00', 'Africa/Addis_Ababa'); // 09:15 UTC, while 400 was in force

    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));
    $history->open($this->sandwich, Money::of('420.00'), CarbonImmutable::now());

    expect($prices->at($this->sandwich, $sentAt))->toBeInstanceOf(Money::class)
        ->and($prices->at($this->sandwich, $sentAt)?->amount())->toBe('400.00')
        ->and($prices->at($this->sandwich, $sentAt)?->currency())->toBe('ETB')
        ->and($prices->at($this->sandwich->id, CarbonImmutable::parse('2026-10-21 09:59:59', 'UTC'))?->amount())->toBe('400.00')
        ->and($prices->at($this->sandwich, CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'))?->amount())->toBe('420.00', 'effective_from is inclusive')
        ->and($prices->current($this->sandwich)?->amount())->toBe('420.00')
        ->and($prices->at($this->sandwich, CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC')))->toBeNull('no price before the first one: Orders refuses the line')
        ->and($prices->at($this->latte, CarbonImmutable::now()))->toBeNull();
})->group('contract:orders->catalog', 'contract');

it('gives Routing::stationFor null for display-case products, and the kitchen or the bar for routed ones', function () {
    $routing = app(Routing::class);

    expect($routing->stationFor($this->cake))->toBeNull('the cashier picks it from the display case: no station step')
        ->and($routing->stationFor($this->water))->toBeNull()
        ->and($routing->stationFor($this->chicken))->toBeNull('an ingredient is never on an order line')
        ->and($routing->stationFor($this->sandwich)?->id)->toBe($this->kitchen->id)
        ->and($routing->stationFor($this->latte->id)?->id)->toBe($this->bar->id)
        ->and($routing->stationFor($this->sandwich))->toBeInstanceOf(Station::class)
        // the cake is still made somewhere: that is Production's routing, not the order line's
        ->and($routing->productionStationFor($this->cake)?->id)->toBe($this->cakeRoom->id)
        ->and($routing->productionStationFor($this->water))->toBeNull();
})->group('contract:orders->catalog', 'contract');

it('returns Modifiers::for with price deltas as money and recipe deltas in base units', function () {
    $b = $this->branch->id;
    Modifier::factory()->create(['branch_id' => $b, 'item_id' => $this->sandwich->id, 'name_en' => 'Extra chicken', 'name_am' => 'ተጨማሪ ዶሮ', 'price_delta' => '60.00',
        'recipe_delta' => [['ingredient_item_id' => $this->chicken->id, 'qty' => '0.05', 'unit_id' => catalogUnit('kg')->id]]]);
    Modifier::factory()->create(['branch_id' => $b, 'item_id' => $this->sandwich->id, 'name_en' => 'No onion', 'name_am' => 'ያለ ሽንኩርት',
        'recipe_delta' => [['ingredient_item_id' => $this->onion->id, 'qty' => '-15', 'unit_id' => catalogUnit('g')->id]]]);
    Modifier::factory()->create(['branch_id' => $b, 'item_id' => $this->latte->id, 'name_en' => 'Large', 'name_am' => 'ትልቅ', 'price_delta' => '15.50',
        'recipe_delta' => [['ingredient_item_id' => $this->milk->id, 'qty' => '0.1', 'unit_id' => catalogUnit('L')->id]]]);
    $gone = Modifier::factory()->create(['branch_id' => $b, 'item_id' => $this->sandwich->id, 'name_en' => 'Old sauce', 'name_am' => 'የቀድሞ ስልስ']);
    $gone->delete();

    $sandwich = app(Modifiers::class)->for($this->sandwich);
    expect($sandwich)->toHaveCount(2, 'a deleted modifier is not offered')
        ->and($sandwich[0])->toBeInstanceOf(ModifierView::class)
        ->and($sandwich[0]->itemId)->toBe($this->sandwich->id)
        ->and($sandwich[0]->name->en)->toBe('Extra chicken')
        ->and($sandwich[0]->name->am)->toBe('ተጨማሪ ዶሮ')
        ->and($sandwich[0]->priceDelta)->toBeInstanceOf(Money::class)
        ->and($sandwich[0]->priceDelta->amount())->toBe('60.00')
        ->and($sandwich[0]->recipeDelta[0])->toBeInstanceOf(RecipeDeltaLine::class)
        ->and($sandwich[0]->recipeDelta[0]->ingredientItemId)->toBe($this->chicken->id)
        ->and($sandwich[0]->recipeDelta[0]->qty->toString())->toBe('50.0000')
        ->and($sandwich[0]->recipeDelta[0]->unit)->toBe('g')
        ->and($sandwich[1]->name->en)->toBe('No onion')
        ->and($sandwich[1]->priceDelta->amount())->toBe('0.00')
        ->and($sandwich[1]->recipeDelta[0]->qty->toString())->toBe('-15.0000')
        ->and($sandwich[1]->recipeDelta[0]->unit)->toBe('g');

    $latte = app(Modifiers::class)->for($this->latte->id);
    expect($latte)->toHaveCount(1)
        ->and($latte[0]->priceDelta->amount())->toBe('15.50')
        ->and($latte[0]->recipeDelta[0]->qty->toString())->toBe('100.0000')
        ->and($latte[0]->recipeDelta[0]->unit)->toBe('ml')
        ->and(app(Modifiers::class)->for($this->water))->toBe([]);
})->group('contract:orders->catalog', 'contract');

it('gives Recipes::activeFor the version a line snapshots, and still answers an older version afterwards', function () {
    $b = $this->branch->id;
    $v1 = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $this->sandwich->id, 'version' => 1, 'is_active' => true, 'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $v1->id, 'ingredient_item_id' => $this->chicken->id, 'qty' => '120', 'unit_id' => catalogUnit('g')->id]);

    $sent = app(Recipes::class)->activeFor($this->sandwich);
    expect($sent?->version)->toBe(1)->and($sent?->recipeId)->toBe($v1->id);

    // the admin publishes v2 (150 g); a line sent under v1 still finds what v1 said
    $v1->forceFill(['is_active' => false])->save();
    $v2 = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $this->sandwich->id, 'version' => 2, 'is_active' => true, 'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id]);
    RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $v2->id, 'ingredient_item_id' => $this->chicken->id, 'qty' => '150', 'unit_id' => catalogUnit('g')->id]);

    expect(app(Recipes::class)->activeFor($this->sandwich)?->version)->toBe(2)
        ->and(app(Recipes::class)->activeFor($this->sandwich)?->lines[0]->qty->toString())->toBe('150.0000')
        ->and(app(Recipes::class)->version($this->sandwich, 1)?->lines[0]->qty->toString())->toBe('120.0000')
        ->and(app(Recipes::class)->version($this->sandwich, 3))->toBeNull()
        // counter products are sold as they come: no recipe version on their lines
        ->and(app(Recipes::class)->activeFor($this->water)?->version)->toBeNull()
        ->and(app(Recipes::class)->activeFor($this->water)?->isEmpty())->toBeTrue();
})->group('contract:orders->catalog', 'contract');

it('tells me with Items::isNew which products go in the New row (local date), and with fulfilment which lines have a station', function () {
    $items = app(Items::class);
    // new up to and including 21 October, Addis Ababa time
    $this->sandwich->forceFill(['is_new_until' => '2026-10-21'])->save();

    $this->travelTo(CarbonImmutable::parse('2026-10-21 23:59:00', 'Africa/Addis_Ababa'));
    expect($items->isNew($this->sandwich->fresh() ?? $this->sandwich))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-10-22 00:01:00', 'Africa/Addis_Ababa')); // still 21 October in UTC
    expect($items->isNew($this->sandwich->fresh() ?? $this->sandwich))->toBeFalse()
        ->and($items->isNew($this->latte))->toBeFalse();

    expect($items->fulfilment($this->sandwich))->toBe(Fulfilment::ToOrder)
        ->and($items->fulfilment($this->cake))->toBe(Fulfilment::ToStock)
        ->and($items->fulfilment($this->water->id))->toBe(Fulfilment::BoughtIn);
})->group('contract:orders->catalog', 'contract');
