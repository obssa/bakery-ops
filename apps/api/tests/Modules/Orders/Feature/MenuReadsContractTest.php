<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\Modifiers;
use App\Modules\Catalog\Contracts\Prices;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Inventory\Contracts\Availability;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Stock;
use App\Shared\Models\Branch;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Tests\Support\OrderFloor;

/*
 * The bulk reads the menu needs (step 1.5, phase-1 plan section 6 item 5): GET /menu must take a fixed number of
 * queries however many products there are, so Catalog and Inventory answer for many products at once. Each bulk answer
 * must agree with the one-product capability it replaces (Prices::at, Routing::stationFor, Modifiers::for,
 * Recipes::activeFor, Locations::forProduct, Availability::ofProduct), and the two fingerprints must move when what
 * the menu shows moves.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $m = $this->floor->menu;
    $this->products = [$m->sandwich, $m->latte, $m->croissant, $m->cake, $m->water];
});

it('Prices::atMany agrees with Prices::at and leaves out the product without a price', function () {
    $m = $this->floor->menu;
    $now = CarbonImmutable::now();
    app(PriceHistory::class)->open($m->latte, Money::of('140.00'), $now->subHour());
    $many = app(Prices::class)->atMany([...$this->products, $m->chicken], $now);

    foreach ($this->products as $product) {
        expect($many[$product->id]->amount())->toBe(app(Prices::class)->at($product, $now)?->amount());
    }
    expect($many)->not->toHaveKey($m->chicken->id)->and(app(Prices::class)->atMany([], $now))->toBe([]);
})->group('module:orders', 'feature');

it('Routing::stationsForMany agrees with Routing::stationFor', function () {
    $m = $this->floor->menu;
    $many = app(Routing::class)->stationsForMany([...$this->products, $m->chicken]);

    foreach ($this->products as $product) {
        expect($many[$product->id]?->id)->toBe(app(Routing::class)->stationFor($product)?->id);
    }
    expect($many[$m->sandwich->id]?->id)->toBe($m->kitchenStation->id)->and($many[$m->croissant->id])->toBeNull()->and($many[$m->chicken->id])->toBeNull();
})->group('module:orders', 'feature');

it('Modifiers::offeredFor lists what Modifiers::for lists, in the same order, with the price delta as Money', function () {
    $m = $this->floor->menu;
    $many = app(Modifiers::class)->offeredFor(array_map(static fn ($p) => $p->id, $this->products));

    foreach ($this->products as $product) {
        $single = app(Modifiers::class)->for($product);
        expect(array_map(static fn ($o) => [$o->id, $o->name->en, $o->name->am, $o->priceDelta->amount()], $many[$product->id] ?? []))
            ->toBe(array_map(static fn ($v) => [$v->id, $v->name->en, $v->name->am, $v->priceDelta->amount()], $single));
    }
    expect($many[$m->sandwich->id])->toHaveCount(3);
})->group('module:orders', 'feature');

it('Recipes::activeForMany agrees with Recipes::activeFor', function () {
    $m = $this->floor->menu;
    $many = app(Recipes::class)->activeForMany([...$this->products, $m->chicken]);

    foreach ($this->products as $product) {
        $single = app(Recipes::class)->activeFor($product);
        expect($many[$product->id]?->version)->toBe($single?->version)
            ->and(array_map(static fn ($l) => [$l->ingredientItemId, $l->qty->toString(), $l->isOptional], $many[$product->id]->lines ?? []))
            ->toBe(array_map(static fn ($l) => [$l->ingredientItemId, $l->qty->toString(), $l->isOptional], $single->lines ?? []));
    }
    expect($many[$m->chicken->id])->toBeNull();
})->group('module:orders', 'feature');

it('Locations::idsForProducts agrees with Locations::forProduct', function () {
    $m = $this->floor->menu;
    $many = app(Locations::class)->idsForProducts($this->products);

    foreach ($this->products as $product) {
        expect($many[$product->id])->toBe(app(Locations::class)->forProduct($product)?->id);
    }
    expect($many[$m->sandwich->id])->toBe($m->kitchen->id)->and($many[$m->latte->id])->toBe($m->bar->id)->and($many[$m->water->id])->toBe($m->display->id);
})->group('module:orders', 'feature');

it('Availability::bulk still agrees with ofProduct for a mixed list at each location', function () {
    $m = $this->floor->menu;
    foreach ([$m->kitchen, $m->bar, $m->display] as $location) {
        $many = app(Availability::class)->bulk($this->products, $location);
        foreach ($this->products as $product) {
            expect($many[$product->id]->toString())->toBe(app(Availability::class)->ofProduct($product, $location)->toString());
        }
    }
})->group('module:orders', 'feature');

it('Stock::fingerprint keeps moving while the newest movement is inside the overlap window, then settles on a tag no poll of the window had', function () {
    // ids become visible in commit order, not id order (ADR 0009): a transaction holding a lower id can commit after a higher one was
    // served, and max(id) alone would not move. So a young newest movement adds a time bucket; once it is older than the window the
    // tag is stable again, and different from every tag the window handed out, so a pad that polled inside it looks again.
    $m = $this->floor->menu;
    stockIn($m->water, $m->display, '1');
    $start = CarbonImmutable::now();
    $stock = fn () => app(Stock::class)->fingerprint();

    $this->travelTo($start->addSeconds(1));
    $first = $stock();
    $this->travelTo($start->addSeconds(2));
    $second = $stock();
    expect($second)->not->toBe($first);

    $this->travelTo($start->addSeconds(9));
    $settled = $stock();
    $this->travelTo($start->addSeconds(12));
    expect($stock())->toBe($settled)->and($settled)->not->toBe($first)->and($settled)->not->toBe($second);
})->group('module:orders', 'feature');

it('Items::menuFingerprint and Stock::fingerprint move when what the menu shows moves, and only then', function () {
    $f = $this->floor;
    $m = $f->menu;
    $this->travel(\App\Shared\Live\OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds(); // past the settling window of the ledger tag
    $now = CarbonImmutable::now();
    $catalog = fn () => app(Items::class)->menuFingerprint($now);
    $stock = fn () => app(Stock::class)->fingerprint();

    $before = $catalog();
    $stockBefore = $stock();
    expect($catalog())->toBe($before)->and($stock())->toBe($stockBefore);

    stockIn($m->water, $m->display, '1');
    expect($stock())->not->toBe($stockBefore)->and($catalog())->toBe($before);

    app(PriceHistory::class)->open($m->water, Money::of('35.00'), $now);
    $afterPrice = $catalog();
    expect($afterPrice)->not->toBe($before);

    $this->travelTo($now->addSeconds(3));
    $m->cake->forceFill(['name_en' => 'Black forest'])->save();
    $afterItem = app(Items::class)->menuFingerprint($now->addSeconds(3));
    expect($afterItem)->not->toBe($afterPrice);

    $m->withTomato->forceFill(['price_delta' => '11.00'])->save();
    $afterModifier = app(Items::class)->menuFingerprint($now->addSeconds(3));
    expect($afterModifier)->not->toBe($afterItem);

    // another branch's catalog and ledger are not part of this branch's fingerprints
    $theirs = Branch::factory()->create();
    Item::factory()->product()->create(['branch_id' => $theirs->id]);
    expect($catalog())->toBe($afterModifier)->and($theirs->id)->not->toBe($f->branch->id);
})->group('module:orders', 'feature');
