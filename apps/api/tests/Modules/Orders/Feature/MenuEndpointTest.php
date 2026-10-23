<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Services\ItemStations;
use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Location;
use App\Shared\Live\OverlapCursor;
use App\Shared\Models\Branch;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrderFloor;

/*
 * GET /menu (docs/phases/phase-1-plan.md section 4, "Where a line's stock is", section 10; step 1.5). The pad's menu: the
 * sellable products with the price now, the stock at the line's own location (the item's station, else the display
 * case; one Availability::bulk call per location), the modifiers, and an ETag that changes when stock, a price or the
 * catalog changes, so an idle pad costs one cheap query set and a 304.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->menu = function (string $query = '', array $headers = []) {
        loginAs($this->floor->waiter);

        return $this->withHeaders($headers)->getJson('/api/v1/menu'.$query);
    };
    $this->entries = function ($response): array {
        $out = [];
        foreach ((array) $response->json('items') as $entry) {
            $out[$entry['item_id']] = $entry;
        }

        return $out;
    };
    $this->queries = function (Closure $call): int {
        $count = 0;
        DB::listen(function (QueryExecuted $q) use (&$count): void {
            $count++;
        });
        $call();

        return $count;
    };
});

it('lists the sellable products with price, stock at the line\'s location, station and modifiers; never ingredients', function () {
    $m = $this->floor->menu;
    $response = ($this->menu)();

    $response->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    expect($response->headers->get('ETag'))->toBeString()
        ->and(array_keys($response->json()))->toBe(['categories', 'items', 'generated_at']);
    $entries = ($this->entries)($response);
    expect(array_keys($entries))->toEqualCanonicalizing([$m->sandwich->id, $m->latte->id, $m->croissant->id, $m->cake->id, $m->water->id])
        ->and($entries)->not->toHaveKey($m->chicken->id)->and($entries)->not->toHaveKey($m->bread->id);

    expect($entries[$m->sandwich->id])->toMatchArray([
        'item_id' => $m->sandwich->id, 'name_en' => 'Chicken sandwich', 'name_am' => 'የዶሮ ሳንድዊች', 'category_id' => null, 'unit' => 'pcs',
        'price' => '250.00', 'currency' => 'ETB', 'available' => '7.0000', 'low' => false, 'is_new' => false,
        'station_id' => $m->kitchenStation->id, 'location_id' => $m->kitchen->id,
    ])->and($entries[$m->sandwich->id])->not->toHaveKey('unavailable_reason');
    expect($entries[$m->sandwich->id]['modifiers'])->toBe([
        ['id' => $m->noOnion->id, 'name_en' => 'No onion', 'name_am' => 'ያለ ሽንኩርት', 'price_delta' => '0.00'],
        ['id' => $m->extraChicken->id, 'name_en' => 'Extra chicken', 'name_am' => 'ተጨማሪ ዶሮ', 'price_delta' => '60.00'],
        ['id' => $m->withTomato->id, 'name_en' => 'With tomato', 'name_am' => 'ከቲማቲም ጋር', 'price_delta' => '10.00'],
    ]);
    expect($entries[$m->latte->id])->toMatchArray(['price' => '120.00', 'station_id' => $m->barStation->id, 'location_id' => $m->bar->id])
        ->and($entries[$m->latte->id]['available'])->toBe('4.0000')
        ->and($entries[$m->croissant->id])->toMatchArray(['available' => '5.0000', 'station_id' => null, 'location_id' => $m->display->id, 'modifiers' => []])
        ->and($entries[$m->cake->id])->toMatchArray(['available' => '1.0000', 'unit' => 'slice', 'low' => true])
        ->and($entries[$m->water->id])->toMatchArray(['available' => '24.0000', 'price' => '30.00']);
    // the ingredients (and their names) never appear anywhere in the answer
    expect($response->getContent())->not->toContain('Chicken breast')->and($response->getContent())->not->toContain('Toast bread');
})->group('module:orders', 'feature');

it('hides a product with nothing available unless include_unavailable=1, which lists it with available 0 and a reason', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->send([$m->line($m->cake)]); // the last slice is now reserved

    $hidden = ($this->entries)(($this->menu)());
    expect($hidden)->not->toHaveKey($m->cake->id)->and($hidden)->toHaveKey($m->croissant->id);

    $all = ($this->entries)(($this->menu)('?include_unavailable=1'));
    expect($all)->toHaveKey($m->cake->id)
        ->and($all[$m->cake->id])->toMatchArray(['available' => '0.0000', 'low' => false, 'unavailable_reason' => 'out_of_stock'])
        ->and($all[$m->croissant->id])->not->toHaveKey('unavailable_reason');
})->group('module:orders', 'feature');

it('lists a made-to-order product as unavailable when the kitchen is out of an ingredient', function () {
    $f = $this->floor;
    $m = $f->menu;
    stockIn($m->bread, $m->kitchen, '-20', MovementReason::Waste);

    expect(($this->entries)(($this->menu)()))->not->toHaveKey($m->sandwich->id);
    expect(($this->entries)(($this->menu)('?include_unavailable=1'))[$m->sandwich->id])->toMatchArray(['available' => '0.0000', 'unavailable_reason' => 'out_of_stock']);
})->group('module:orders', 'feature');

it('marks low at 3 or fewer and not at 4', function () {
    $f = $this->floor;
    $m = $f->menu;
    expect(($this->entries)(($this->menu)())[$m->croissant->id])->toMatchArray(['available' => '5.0000', 'low' => false]);

    $f->send([$m->line($m->croissant)]);
    expect(($this->entries)(($this->menu)())[$m->croissant->id])->toMatchArray(['available' => '4.0000', 'low' => false]);

    $f->send([$m->line($m->croissant)]);
    expect(($this->entries)(($this->menu)())[$m->croissant->id])->toMatchArray(['available' => '3.0000', 'low' => true]);
})->group('module:orders', 'feature');

it('takes is_new from Catalog, in the branch calendar', function () {
    $m = $this->floor->menu;
    $m->latte->forceFill(['is_new_until' => CarbonImmutable::now('Africa/Addis_Ababa')->toDateString()])->save();
    $m->croissant->forceFill(['is_new_until' => CarbonImmutable::now('Africa/Addis_Ababa')->subDay()->toDateString()])->save();

    $entries = ($this->entries)(($this->menu)());
    expect($entries[$m->latte->id]['is_new'])->toBeTrue()->and($entries[$m->croissant->id]['is_new'])->toBeFalse()->and($entries[$m->cake->id]['is_new'])->toBeFalse();
})->group('module:orders', 'feature');

it('prices each product at now and leaves out a product with no price', function () {
    $f = $this->floor;
    $m = $f->menu;
    $prices = app(PriceHistory::class);
    $prices->open($m->latte, Money::of('140.00'), CarbonImmutable::now()->subHour());
    $prices->open($m->croissant, Money::of('99.00'), CarbonImmutable::now()->addHour()); // scheduled, not in force yet
    $unpriced = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $f->branch->id, 'name_en' => 'Gum']);
    stockIn($unpriced, $m->display, '5');

    $entries = ($this->entries)(($this->menu)());
    expect($entries[$m->latte->id]['price'])->toBe('140.00')->and($entries[$m->croissant->id]['price'])->toBe('60.00')->and($entries)->not->toHaveKey($unpriced->id);
})->group('module:orders', 'feature');

it('leaves out inactive and deleted products', function () {
    $m = $this->floor->menu;
    $m->water->forceFill(['is_active' => false])->save();
    $m->cake->delete();

    $entries = ($this->entries)(($this->menu)());
    expect($entries)->not->toHaveKey($m->water->id)->and($entries)->not->toHaveKey($m->cake->id)->and($entries)->toHaveKey($m->croissant->id);
})->group('module:orders', 'feature');

it('asks one location for every product with ?location=', function () {
    $m = $this->floor->menu;
    $entries = ($this->entries)(($this->menu)('?location='.$m->bar->id));

    // at the bar only the latte can be made (espresso, milk); the rest has nothing there
    expect(array_keys($entries))->toBe([$m->latte->id])->and($entries[$m->latte->id]['location_id'])->toBe($m->bar->id);

    $all = ($this->entries)(($this->menu)('?include_unavailable=1&location='.$m->display->id));
    expect(array_unique(array_column($all, 'location_id')))->toBe([$m->display->id])
        ->and($all[$m->croissant->id]['available'])->toBe('5.0000')->and($all[$m->sandwich->id]['available'])->toBe('0.0000');
})->group('module:orders', 'feature');

it('refuses a location of another branch or one that does not exist with 422', function () {
    $other = Branch::factory()->create();
    $theirs = Location::factory()->ofType(LocationType::Kitchen)->create(['branch_id' => $other->id]);

    ($this->menu)('?location='.$theirs->id)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->menu)('?location=999999')->assertStatus(422);
    ($this->menu)('?location=abc')->assertStatus(422);
})->group('module:orders', 'feature');

it('sorts the categories and lists the ones the menu uses', function () {
    $f = $this->floor;
    $m = $f->menu;
    $b = $f->branch->id;
    $drinks = Category::factory()->product()->create(['branch_id' => $b, 'name_en' => 'Drinks', 'name_am' => 'መጠጦች', 'sort' => 2]);
    $food = Category::factory()->product()->create(['branch_id' => $b, 'name_en' => 'Food', 'name_am' => 'ምግብ', 'sort' => 1]);
    Category::factory()->product()->create(['branch_id' => $b, 'name_en' => 'Unused', 'sort' => 0]);
    $m->latte->forceFill(['category_id' => $drinks->id])->save();
    $m->sandwich->forceFill(['category_id' => $food->id])->save();

    $response = ($this->menu)();
    expect($response->json('categories'))->toBe([
        ['id' => $food->id, 'name_en' => 'Food', 'name_am' => 'ምግብ', 'sort' => 1],
        ['id' => $drinks->id, 'name_en' => 'Drinks', 'name_am' => 'መጠጦች', 'sort' => 2],
    ])->and(($this->entries)($response)[$m->latte->id]['category_id'])->toBe($drinks->id);
})->group('module:orders', 'feature');

it('never shows another branch\'s products, categories or stock', function () {
    $other = Branch::factory()->create();
    $theirs = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $other->id, 'name_en' => 'Their water']);
    Category::factory()->product()->create(['branch_id' => $other->id, 'name_en' => 'Their category']);

    $response = ($this->menu)();
    expect($response->getContent())->not->toContain('Their water')->and($response->getContent())->not->toContain('Their category')
        ->and(($this->entries)($response))->not->toHaveKey($theirs->id);
})->group('module:orders', 'feature');

it('is for the order takers: orders.create', function () {
    $f = $this->floor;
    foreach ([$f->chef, $f->barista, $f->cakeBaker, $f->breadBaker] as $user) {
        loginAs($user);
        $this->getJson('/api/v1/menu')->assertForbidden();
    }
    foreach ([$f->admin, $f->manager, $f->cashier, $f->waiter] as $user) {
        loginAs($user);
        $this->getJson('/api/v1/menu')->assertOk();
    }
})->group('module:orders', 'feature');

it('answers 304 with no body when If-None-Match is current, and does not build the menu to do it', function () {
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds(); // past the settling window of the ledger tag
    $first = ($this->menu)();
    $etag = $first->headers->get('ETag');

    $touched = [];
    DB::listen(function (QueryExecuted $q) use (&$touched): void {
        $touched[] = $q->sql;
    });
    $again = ($this->menu)('', ['If-None-Match' => $etag]);

    $again->assertStatus(304);
    expect($again->getContent())->toBe('')->and($again->headers->get('ETag'))->toBe($etag)->and($again->headers->get('Cache-Control'))->toBe('no-cache, private');
    foreach ($touched as $sql) {
        expect($sql)->not->toContain('stock_balances')->and($sql)->not->toContain('item_stock_view');
        expect($sql)->not->toContain('from `items`')->and($sql)->not->toContain('from `modifiers`'); // the catalog counter, never the rows
    }
    expect(($this->menu)('', ['If-None-Match' => 'W/'.$etag])->status())->toBe(304);
    expect(($this->menu)('', ['If-None-Match' => '"other"'])->status())->toBe(200);
    expect($etag)->toBe(($this->menu)()->headers->get('ETag')); // idle: the same tag
})->group('module:orders', 'feature');

it('moves the ETag on every poll while a movement is younger than the overlap window, and answers 304 again after it', function () {
    $m = $this->floor->menu;
    stockIn($m->water, $m->display, '2');
    $start = CarbonImmutable::now();
    $etag = fn () => ($this->menu)()->headers->get('ETag');

    $this->travelTo($start->addSeconds(1));
    $inside = $etag();
    $this->travelTo($start->addSeconds(2));
    expect($etag())->not->toBe($inside); // a late commit below the highest id could be in this window: never a 304 over it

    $this->travelTo($start->addSeconds(9));
    $settled = $etag();
    expect($settled)->not->toBe($inside);
    $this->travelTo($start->addSeconds(12));
    expect(($this->menu)('', ['If-None-Match' => $settled])->status())->toBe(304);
    expect(($this->menu)('', ['If-None-Match' => $inside])->status())->toBe(200);
})->group('module:orders', 'feature');

it('changes the ETag when stock changes, when a price changes, when the catalog changes, and per query string', function () {
    $f = $this->floor;
    $m = $f->menu;
    $etag = fn (string $query = '') => ($this->menu)($query)->headers->get('ETag');
    $base = $etag();
    expect($etag('?include_unavailable=1'))->not->toBe($base)->and($etag('?location='.$m->bar->id))->not->toBe($base);

    $f->send([$m->line($m->croissant)]); // a reservation is a ledger movement
    $afterStock = $etag();
    expect($afterStock)->not->toBe($base);

    stockIn($m->water, $m->display, '5');
    $afterReceipt = $etag();
    expect($afterReceipt)->not->toBe($afterStock);

    app(PriceHistory::class)->open($m->water, Money::of('35.00'), CarbonImmutable::now());
    $afterPrice = $etag();
    expect($afterPrice)->not->toBe($afterReceipt);

    $this->travelTo(CarbonImmutable::now()->addSeconds(2));
    $m->withTomato->forceFill(['price_delta' => '12.00'])->save();
    $afterModifier = $etag();
    expect($afterModifier)->not->toBe($afterPrice);

    $this->travelTo(CarbonImmutable::now()->addSeconds(2));
    $m->cake->forceFill(['name_en' => 'Black forest'])->save();
    $afterName = $etag();
    expect($afterName)->not->toBe($afterModifier);

    $this->travelTo(CarbonImmutable::now()->addDay());
    expect(($this->menu)('', ['If-None-Match' => $afterName])->status())->toBe(200); // a new day: is_new may roll over
})->group('module:orders', 'feature');

it('changes the ETag for every catalog edit, even two inside the same second (a client must never get a 304 over a missed edit)', function () {
    $m = $this->floor->menu;
    $etag = fn () => ($this->menu)()->headers->get('ETag');
    $this->travelTo(CarbonImmutable::now()->startOfSecond()->addSeconds(10)); // past the settling window; frozen: every write below shares one stamp

    $first = $etag();
    $m->cake->forceFill(['name_en' => 'Black forest'])->save();
    $second = $etag();
    $m->cake->forceFill(['name_en' => 'White forest'])->save();
    $third = $etag();
    $m->withTomato->forceFill(['price_delta' => '13.00'])->save();
    $fourth = $etag();
    $m->cake->delete(); // a soft delete, still the same second
    $fifth = $etag();

    expect([$first, $second, $third, $fourth, $fifth])->toHaveCount(5)->and(array_unique([$first, $second, $third, $fourth, $fifth]))->toHaveCount(5);
    expect(($this->menu)('', ['If-None-Match' => $second])->status())->toBe(200);
    expect(($this->menu)('', ['If-None-Match' => $fifth])->status())->toBe(304);
})->group('module:orders', 'feature');

it('takes a fixed number of queries however many products the menu has', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->menu)(); // warm any one-off lookups
    $few = ($this->queries)(fn () => ($this->menu)()->assertOk());

    $extra = function (int $n) use ($f, $m): void {
        $b = $f->branch->id;
        for ($i = 0; $i < $n; $i++) {
            $made = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b, 'name_en' => "Special {$i}"]);
            $recipe = Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $made->id, 'version' => 1, 'is_active' => true, 'yield_qty' => '1', 'yield_unit_id' => catalogUnit('pcs')->id]);
            RecipeLine::factory()->create(['branch_id' => $b, 'recipe_id' => $recipe->id, 'ingredient_item_id' => $m->lettuce->id, 'qty' => '10', 'unit_id' => catalogUnit('g')->id]);
            app(ItemStations::class)->replace($made, [$m->kitchenStation->id]);
            Modifier::factory()->create(['branch_id' => $b, 'item_id' => $made->id, 'name_en' => 'Extra', 'name_am' => 'ተጨማሪ', 'price_delta' => '5.00', 'recipe_delta' => []]);
            app(PriceHistory::class)->open($made, Money::of('80.00'), CarbonImmutable::now()->subDay());
            $stocked = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $b, 'name_en' => "Shelf {$i}"]);
            app(PriceHistory::class)->open($stocked, Money::of('20.00'), CarbonImmutable::now()->subDay());
            stockIn($stocked, $m->display, '9');
        }
    };
    $extra(12);
    $count = 0;
    $many = ($this->queries)(function () use (&$count): void {
        $count = count(($this->menu)()->assertOk()->json('items'));
    });

    expect($count)->toBe(5 + 24)->and($many)->toBe($few)->and($few)->toBeLessThanOrEqual(40);
})->group('module:orders', 'feature');
