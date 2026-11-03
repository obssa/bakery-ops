<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Catalog\Services\ProductPhotos;
use App\Shared\Live\OverlapCursor;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

/*
 * Step 6.1b (phase-6 plan 4.3, ADR 0022): GET /menu puts each product's display photo on its entry, `image: null | {thumb, card, large,
 * width, height}`, read through Catalog's ItemImages::for once per build (contract orders->catalog), so the pad sells from a photo grid
 * without one query per product. The URLs are the public media route's absolute paths; nothing says where the files live. A photo set,
 * replaced or removed moves catalog_version, so a poll gets a 200 with a new ETag and the new photo, and a poll with nothing changed still
 * gets its 304.
 */

beforeEach(function () {
    Storage::fake(config('catalog.images.disk'));
    config(['catalog.images.format' => 'jpeg']);
    $this->floor = OrderFloor::open($this);
    $this->menu = function (array $headers = [], string $query = '') {
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
    // the admin sets and removes a photo through the API, as the product page will
    $this->setPhoto = function (Item $item, string $bytes): array {
        loginAs($this->floor->admin);

        return photoPost($this, $item->id, $bytes)->assertCreated()->json('data.image');
    };
    $this->removePhoto = function (Item $item): void {
        loginAs($this->floor->admin);
        $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->deleteJson("/api/v1/items/{$item->id}/image")->assertOk();
    };
    $this->view = fn (array $image): array => [
        'thumb' => $image['thumb'], 'card' => $image['card'], 'large' => $image['large'], 'width' => $image['width'], 'height' => $image['height'],
    ];
});

it('puts each product\'s photo on its menu entry as {thumb, card, large, width, height}, and null for one without a photo or whose photo was removed', function () {
    $m = $this->floor->menu;
    $sandwich = ($this->setPhoto)($m->sandwich, imgJpeg(2000, 1500));
    $latte = ($this->setPhoto)($m->latte, imgJpeg(1000, 750));
    ($this->setPhoto)($m->cake, imgJpeg(1200, 900));
    ($this->removePhoto)($m->cake);

    $response = ($this->menu)()->assertOk();
    $entries = ($this->entries)($response);

    expect($entries[$m->sandwich->id]['image'])->toBe(($this->view)($sandwich))
        ->and([$sandwich['width'], $sandwich['height']])->toBe([1600, 1200], 'the large rendition\'s size, which the srcSet reads')
        ->and($entries[$m->latte->id]['image'])->toBe(($this->view)($latte))
        ->and([$latte['width'], $latte['height']])->toBe([1000, 750], 'never upscaled')
        ->and($entries[$m->cake->id])->toHaveKey('image')
        ->and($entries[$m->cake->id]['image'])->toBeNull('its photo was removed')
        ->and($entries[$m->croissant->id]['image'])->toBeNull('no photo yet')
        ->and($entries[$m->water->id]['image'])->toBeNull();

    foreach (['thumb', 'card', 'large'] as $rendition) {
        $url = $entries[$m->sandwich->id]['image'][$rendition];
        expect($url)->toMatch('#^/api/v1/media/items/[0-9a-f]{64}/'.$rendition.'\.jpg$#', 'an absolute path on the API\'s origin, no host');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    // an unavailable product listed with include_unavailable carries its photo too
    $f = $this->floor;
    $f->send([$m->line($m->cake)]); // the last slice is now reserved
    ($this->setPhoto)($m->cake, imgJpeg(1200, 900));
    $all = ($this->entries)(($this->menu)([], '?include_unavailable=1'));
    expect($all[$m->cake->id])->toMatchArray(['available' => '0.0000', 'unavailable_reason' => 'out_of_stock'])
        ->and($all[$m->cake->id]['image'])->toBeArray()->toHaveKeys(['thumb', 'card', 'large', 'width', 'height']);
})->group('module:orders', 'feature');

it('says nothing about where the files live: no disk, key prefix or folder in the body', function () {
    $m = $this->floor->menu;
    ($this->setPhoto)($m->sandwich, imgJpeg(1200, 900));
    ($this->setPhoto)($m->latte, imgJpeg(1200, 900, 80));

    $body = (string) json_encode(($this->menu)()->assertOk()->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $prefixes = DB::table('item_images')->pluck('key_prefix')->all();

    expect($prefixes)->toHaveCount(2)->and($body)->toContain('/api/v1/media/items/');
    foreach ($prefixes as $prefix) {
        expect($body)->not->toContain((string) $prefix);
    }
    expect($body)->not->toContain(storage_path())->not->toContain('"disk"')->not->toContain('private')->not->toContain('"sha256"');
})->group('module:orders', 'feature');

it('reads every product\'s photo in one query, however many products have one', function () {
    $f = $this->floor;
    $m = $f->menu;
    ($this->setPhoto)($m->sandwich, imgJpeg(800, 600));
    ($this->setPhoto)($m->water, imgJpeg(804, 603));
    $build = function (): array {
        $sql = [];
        DB::listen(function (QueryExecuted $q) use (&$sql): void {
            $sql[] = $q->sql;
        });
        $count = count(($this->menu)()->assertOk()->json('items'));

        return [$count, count($sql), count(array_filter($sql, fn (string $s): bool => str_contains($s, 'item_images')))];
    };
    ($this->menu)(); // warm any one-off lookups
    [$fewItems, $fewQueries, $fewImageQueries] = $build();

    for ($i = 0; $i < 6; $i++) {
        withActor($f->admin->id);
        $shelf = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $f->branch->id, 'name_en' => "Shelf {$i}"]);
        app(PriceHistory::class)->open($shelf, Money::of('20.00'), CarbonImmutable::now()->subDay());
        stockIn($shelf, $m->display, '9');
        app(ProductPhotos::class)->set($shelf, imgJpeg(800 + 4 * $i, 600 + 3 * $i), null, (string) Str::uuid());
    }
    [$manyItems, $manyQueries, $manyImageQueries] = $build();

    expect($fewItems)->toBe(5)
        ->and($manyItems)->toBe(11)
        ->and($fewImageQueries)->toBe(1, 'one read of the photos per build')
        ->and($manyImageQueries)->toBe(1, 'eight photos, still one read')
        ->and($manyQueries)->toBe($fewQueries, 'no query per product');
})->group('module:orders', 'feature');

it('moves the ETag when a photo is set, replaced or removed (a 200 with the new photo), and answers 304 while nothing changed', function () {
    $m = $this->floor->menu;
    $this->travel(OverlapCursor::fromConfig()->windowSeconds() + 1)->seconds(); // past the settling window of the ledger tag
    $poll = function (?string $etag) use ($m): array {
        $response = ($this->menu)($etag === null ? [] : ['If-None-Match' => $etag]);

        return [$response->status(), (string) $response->headers->get('ETag'), $response->status() === 200 ? ($this->entries)($response)[$m->sandwich->id]['image'] : 'none'];
    };

    [, $start, $image] = $poll(null);
    expect($image)->toBeNull()
        ->and($poll($start)[0])->toBe(304, 'nothing changed');

    $first = ($this->setPhoto)($m->sandwich, imgJpeg(1200, 900));
    [$status, $afterSet, $image] = $poll($start);
    expect($status)->toBe(200, 'a photo was set: the old tag is stale')
        ->and($afterSet)->not->toBe($start)
        ->and($image)->toBe(($this->view)($first))
        ->and($poll($afterSet)[0])->toBe(304);

    $second = ($this->setPhoto)($m->sandwich, imgJpeg(1400, 1050));
    [$status, $afterReplace, $image] = $poll($afterSet);
    expect($status)->toBe(200, 'replaced')
        ->and($afterReplace)->not->toBe($afterSet)->not->toBe($start)
        ->and($image)->toBe(($this->view)($second))
        ->and($image['card'])->not->toBe($first['card'], 'a new photo, new URLs')
        ->and($poll($afterReplace)[0])->toBe(304);

    ($this->removePhoto)($m->sandwich);
    [$status, $afterRemove, $image] = $poll($afterReplace);
    expect($status)->toBe(200, 'removed')
        ->and($afterRemove)->not->toBe($afterReplace)->not->toBe($afterSet)->not->toBe($start)
        ->and($image)->toBeNull()
        ->and($poll($afterRemove)[0])->toBe(304);

    ($this->removePhoto)($m->sandwich); // nothing to remove: no write, so no new tag
    expect($poll($afterRemove)[0])->toBe(304, 'a remove without a photo moves nothing');
})->group('module:orders', 'feature');
