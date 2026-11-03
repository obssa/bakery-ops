<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\ItemImages;
use App\Modules\Catalog\Contracts\ItemImageView;
use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\Modifiers;
use App\Modules\Catalog\Contracts\ModifierView;
use App\Modules\Catalog\Contracts\Prices;
use App\Modules\Catalog\Contracts\RecipeDeltaLine;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\RefundPolicy;
use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Services\ItemStations;
use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Catalog\Services\ProductPhotos;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Contract: orders -> catalog (manifest contracts["orders->catalog"]).
 *
 * Written BEFORE any code exists in App\Modules\Orders (step 1.1): only Catalog classes and factories. Phrased from
 * Orders' side: "when I, Orders, put a product on an order line, I rely on Catalog for the price in force when the
 * order was sent, the station the line goes to (none for the display case), the modifiers with their price and recipe
 * deltas, the recipe version I snapshot, and whether the product is new"; since step 4.1b also "whether a product may be refunded and
 * whether a refunded unit goes back into stock" (RefundPolicy); since step 6.1a also "the product's display photo, as three URLs I can put on
 * the menu" (ItemImages, written red before Catalog had it). It runs in Catalog's CI job (mod-catalog).
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

// Step 4.1b (Ramin's answer to R-C, 2026-10-28): what a refund does is the admin's decision per product, two Catalog settings. Orders reads
// them through RefundPolicy::for at the moment of the refund and never reads Catalog's tables itself.

it('answers RefundPolicy::for(ids) with each product\'s two refund settings as they stand now: refundable, and whether a refunded unit goes back into stock', function () {
    $policy = app(RefundPolicy::class);
    $theirs = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => Branch::factory()->create()->id]);
    $ids = [$this->water->id, $this->cake->id, $this->sandwich->id, $this->chicken->id, $theirs->id, 987654];
    $rule = fn (array $rules, Item $item): array => $rules[$item->id]->toArray();

    $rules = $policy->for($ids);

    // the natural defaults: sold from stock goes back into stock, made to order does not (its ingredients are used), an ingredient is not sold
    $ours = [$this->sandwich->id, $this->cake->id, $this->water->id, $this->chicken->id];
    sort($ours);
    expect(array_keys($rules))->toBe($ours, 'in id order; another branch\'s ids and unknown ids are left out')
        ->and($rule($rules, $this->cake))->toBe(['item_id' => $this->cake->id, 'refundable' => true, 'restock' => true])
        ->and($rule($rules, $this->water))->toBe(['item_id' => $this->water->id, 'refundable' => true, 'restock' => true])
        ->and($rule($rules, $this->sandwich))->toBe(['item_id' => $this->sandwich->id, 'refundable' => true, 'restock' => false])
        ->and($rule($rules, $this->chicken))->toBe(['item_id' => $this->chicken->id, 'refundable' => false, 'restock' => false]);

    // the admin changes them; the next read sees the change, and a product deleted since its sale is still answered
    $this->cake->forceFill(['refund_restock' => false])->save();
    $this->water->forceFill(['refundable' => false])->save();
    $this->latte->delete();
    $after = $policy->for([$this->cake->id, $this->water->id, $this->latte->id]);

    expect($rule($after, $this->cake))->toBe(['item_id' => $this->cake->id, 'refundable' => true, 'restock' => false])
        ->and($rule($after, $this->water))->toBe(['item_id' => $this->water->id, 'refundable' => false, 'restock' => true])
        ->and($rule($after, $this->latte))->toBe(['item_id' => $this->latte->id, 'refundable' => true, 'restock' => false])
        ->and($after[$this->cake->id]->refundable)->toBeTrue()
        ->and($after[$this->cake->id]->restock)->toBeFalse()
        ->and($policy->for([]))->toBe([]);
})->group('contract:orders->catalog', 'contract');

// Phase 6 (step 6.1a, phase-6 plan section 2): the product's display photo. MenuBuilder (6.1b) puts it on every menu item and reads it
// only through ItemImages::for, so these cases say what Orders relies on: one URL per rendition, as an absolute path on the API's own
// origin; the large rendition's size for the srcSet; null for a product without a current photo; one query however many products; a
// photo change moves the catalog_version the menu's ETag reads; and nothing in the answer about where the files live.

it('answers ItemImages::for(ids) with item id => {thumb, card, large, width, height} for a product with a current photo and null for one without or whose photo was removed; another branch\'s ids are left out', function () {
    Storage::fake(config('catalog.images.disk'));
    $photos = app(ProductPhotos::class);
    $photos->set($this->sandwich, imgJpeg(2000, 1500), null, (string) Str::uuid());
    $photos->set($this->latte, imgJpeg(1000, 750), null, (string) Str::uuid());
    $photos->set($this->cake, imgJpeg(1200, 900), null, (string) Str::uuid());
    $photos->remove($this->cake->fresh() ?? $this->cake);

    $mine = $this->branch;
    $theirs = actInBranch(Branch::factory()->create());
    withActor(makeUser($theirs, 'admin')->id);
    $theirCake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $theirs->id]);
    $photos->set($theirCake, imgJpeg(1600, 1200), null, (string) Str::uuid());
    actInBranch($mine);
    withActor(makeUser($mine, 'admin')->id);

    $ids = [$this->water->id, $this->latte->id, $this->sandwich->id, $this->cake->id, $theirCake->id, 987654];
    $images = app(ItemImages::class)->for($ids);
    $url = '#^/api/v1/media/items/([0-9a-f]{64})/(thumb|card|large)\.(webp|jpg)$#';

    $ours = [$this->sandwich->id, $this->latte->id, $this->cake->id, $this->water->id];
    sort($ours);
    expect(array_keys($images))->toBe($ours, 'in id order; another branch\'s ids and unknown ids are left out')
        ->and($images[$this->water->id])->toBeNull('no photo yet')
        ->and($images[$this->cake->id])->toBeNull('its photo was removed')
        ->and($images[$this->sandwich->id])->toBeInstanceOf(ItemImageView::class)
        ->and(array_keys($images[$this->sandwich->id]->toArray()))->toBe(['thumb', 'card', 'large', 'width', 'height'])
        ->and([$images[$this->sandwich->id]->width, $images[$this->sandwich->id]->height])->toBe([1600, 1200], 'the large rendition: 2000 x 1500 shrunk to 1600 x 1200')
        ->and([$images[$this->latte->id]->width, $images[$this->latte->id]->height])->toBe([1000, 750], 'never upscaled: a 1000 x 750 photo stays 1000 x 750')
        ->and(app(ItemImages::class)->for([]))->toBe([]);

    foreach ([$this->sandwich->id, $this->latte->id] as $id) {
        $view = $images[$id];
        $shas = [];
        $exts = [];
        foreach (['thumb' => $view->thumb, 'card' => $view->card, 'large' => $view->large] as $rendition => $value) {
            expect(preg_match($url, $value, $m))->toBe(1, "{$rendition}: an absolute path on the API's origin, no host: {$value}")
                ->and($m[2])->toBe($rendition);
            $shas[] = $m[1];
            $exts[] = $m[3];
        }
        expect(array_unique($shas))->toHaveCount(1, 'the three renditions are named by one sha256')
            ->and(array_unique($exts))->toHaveCount(1, 'and share one format');
    }
    expect($images[$this->sandwich->id]->thumb)->not->toBe($images[$this->latte->id]->thumb, 'two photos, two names');
})->group('contract:orders->catalog', 'contract');

it('moves Items::menuFingerprint()[catalog_version] when a photo is set, replaced or removed, so the menu\'s ETag moves; a remove without a photo moves nothing', function () {
    Storage::fake(config('catalog.images.disk'));
    $photos = app(ProductPhotos::class);
    $version = fn (): int => (int) app(Items::class)->menuFingerprint()['catalog_version'];

    $start = $version();
    $photos->set($this->sandwich, imgJpeg(1200, 900), null, (string) Str::uuid());
    $afterSet = $version();
    $photos->set($this->sandwich->fresh() ?? $this->sandwich, imgJpeg(1400, 1050), null, (string) Str::uuid());
    $afterReplace = $version();
    $photos->remove($this->sandwich->fresh() ?? $this->sandwich);
    $afterRemove = $version();
    $photos->remove($this->sandwich->fresh() ?? $this->sandwich);

    expect($afterSet)->toBeGreaterThan($start)
        ->and($afterReplace)->toBeGreaterThan($afterSet)
        ->and($afterRemove)->toBeGreaterThan($afterReplace)
        ->and($version())->toBe($afterRemove, 'nothing to remove: no write, no new ETag')
        ->and(app(ItemImages::class)->for([$this->sandwich->id])[$this->sandwich->id])->toBeNull();
})->group('contract:orders->catalog', 'contract');

it('reads the photos of any number of products in one query, and the answer never holds a disk path or a key prefix', function () {
    Storage::fake(config('catalog.images.disk'));
    $photos = app(ProductPhotos::class);
    $products = [$this->sandwich, $this->latte, $this->cake, $this->water];
    foreach ($products as $i => $product) {
        $photos->set($product, imgJpeg(800 + 4 * $i, 600 + 3 * $i), null, (string) Str::uuid());
    }
    $more = Item::factory()->count(6)->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);

    $selects = function (array $ids): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ItemImages::class)->for($ids);
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $log;
    };
    $two = $selects([$this->sandwich->id, $this->latte->id]);
    $all = $selects(array_merge(array_map(fn (Item $p): int => $p->id, $products), $more->modelKeys()));

    expect($two)->toHaveCount(1)
        ->and($all)->toHaveCount(1, 'ten ids, still one query')
        ->and($all[0])->toContain('item_images');

    $json = (string) json_encode(array_map(fn (?ItemImageView $v): ?array => $v?->toArray(), app(ItemImages::class)->for([$this->sandwich->id, $this->latte->id])), JSON_UNESCAPED_SLASHES);
    $prefixes = DB::table('item_images')->pluck('key_prefix')->all();
    expect($prefixes)->not->toBeEmpty();
    foreach ($prefixes as $prefix) {
        expect($json)->not->toContain((string) $prefix);
    }
    expect($json)->not->toContain(storage_path())
        ->and($json)->not->toContain('private')
        ->and($json)->not->toContain('"disk"');
})->group('contract:orders->catalog', 'contract');
