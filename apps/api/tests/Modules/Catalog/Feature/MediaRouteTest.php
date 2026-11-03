<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemImage;
use App\Modules\Catalog\Services\ProductPhotos;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Step 6.1a (phase-6 plan 4.2, ADR 0022): GET /api/v1/media/items/{sha}/{rendition}.{ext}, the public, content-addressed route of a
 * product photo's renditions. No sign-in, no session, no cookie: nobody signs in here. It answers the file with its type, an immutable
 * year of caching, an ETag and nosniff; a 304 to a matching If-None-Match, weak or strong; a 404 for an unknown sha, a wrong extension,
 * a wrong rendition, a soft-deleted row or a missing file; and a photo two items share is served while either row is live.
 */

beforeEach(function () {
    Storage::fake(config('catalog.images.disk'));
    config(['catalog.images.format' => 'jpeg']);
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
    $this->photos = app(ProductPhotos::class);
    $this->cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    $this->row = $this->photos->set($this->cake, imgJpeg(1000, 750), null, (string) Str::uuid());
    $this->url = fn (string $rendition, ?string $ext = null, ?string $sha = null): string => '/api/v1/media/items/'.($sha ?? $this->row->sha256)."/{$rendition}.".($ext ?? 'jpg');
});

it('answers each rendition without a sign-in: the file, its type and length, immutable for a year, an ETag, nosniff and no cookie', function () {
    foreach (['thumb', 'card', 'large'] as $rendition) {
        $response = $this->get(($this->url)($rendition));

        $response->assertOk();
        expect($response->streamedContent())->toBe(Storage::disk('media')->get($this->row->path($rendition)))
            ->and($response->headers->get('Content-Type'))->toBe('image/jpeg')
            ->and($response->headers->get('Content-Length'))->toBe((string) strlen($response->streamedContent()))
            ->and($response->headers->get('Cache-Control'))->toBe('immutable, max-age=31536000, public')
            ->and($response->headers->get('ETag'))->toBe("\"{$this->row->sha256}-{$rendition}\"")
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->has('Set-Cookie'))->toBeFalse()
            ->and($response->headers->getCookies())->toBe([]);
    }

    $route = Route::getRoutes()->getByName('catalog.media.show');
    expect($route?->uri())->toBe('api/v1/media/items/{sha}/{rendition}.{ext}')
        ->and($route?->gatherMiddleware())->toBe(['api'], 'no auth:sanctum, no actor, no session');
})->group('module:catalog', 'feature');

it('answers 304 to a matching If-None-Match, weak or strong, alone or in a list, and 200 to another tag', function () {
    $tag = "\"{$this->row->sha256}-card\"";

    foreach ([$tag, "W/{$tag}", "\"other\", W/{$tag}", '*'] as $ifNoneMatch) {
        $response = $this->withHeaders(['If-None-Match' => $ifNoneMatch])->get(($this->url)('card'));
        $response->assertStatus(304);
        expect($response->getContent())->toBe('', $ifNoneMatch)
            ->and($response->headers->get('ETag'))->toBe($tag)
            ->and($response->headers->get('Cache-Control'))->toBe('immutable, max-age=31536000, public');
    }

    $this->withHeaders(['If-None-Match' => "\"{$this->row->sha256}-thumb\""])->get(($this->url)('card'))->assertOk();
})->group('module:catalog', 'feature');

it('answers 404 for an unknown sha, a wrong extension, a wrong rendition or a malformed sha', function () {
    $this->get(($this->url)('card', 'jpg', str_repeat('a', 64)))->assertNotFound();
    $this->get(($this->url)('card', 'webp'))->assertNotFound();
    $this->get(($this->url)('card', 'png'))->assertNotFound();
    $this->get(($this->url)('huge'))->assertNotFound();
    $this->get(($this->url)('card', 'jpg', strtoupper($this->row->sha256)))->assertNotFound();
    $this->get(($this->url)('card', 'jpg', substr($this->row->sha256, 0, 63)))->assertNotFound();
    $this->get('/api/v1/media/items/'.$this->row->sha256.'/card')->assertNotFound();
})->group('module:catalog', 'feature');

it('answers 404 once the row is soft-deleted, and when the file is missing', function () {
    $this->photos->remove($this->cake);
    $this->get(($this->url)('card'))->assertNotFound();

    $tart = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    $row = $this->photos->set($tart, imgJpeg(1200, 900), null, (string) Str::uuid());
    Storage::disk('media')->delete($row->path('large'));

    $this->get(($this->url)('card', 'jpg', $row->sha256))->assertOk();
    $this->get(($this->url)('large', 'jpg', $row->sha256))->assertNotFound();
})->group('module:catalog', 'feature');

it('serves a photo two items share while either row is live, from one set of files, whichever branch asks', function () {
    $tart = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    $shared = $this->photos->set($tart, imgJpeg(1000, 750), null, (string) Str::uuid());

    expect($shared->sha256)->toBe($this->row->sha256, 'the same photo, the same name')
        ->and(Storage::disk('media')->allFiles())->toHaveCount(3);

    $this->photos->remove($this->cake);
    $this->get(($this->url)('card'))->assertOk();

    // a request with another branch signed in, and one with none: the route reads no branch
    actInBranch(Branch::factory()->create());
    $this->get(($this->url)('thumb'))->assertOk();

    actInBranch($this->branch);
    withActor(makeUser($this->branch, 'admin')->id);
    $this->photos->remove($tart);
    expect(ItemImage::query()->count())->toBe(0);
    $this->get(($this->url)('card'))->assertNotFound();
})->group('module:catalog', 'feature');
