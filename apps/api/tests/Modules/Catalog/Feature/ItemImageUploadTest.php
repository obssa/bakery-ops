<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemImage;
use App\Shared\Files\ImageProcessor;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Step 6.1a (phase-6 plan 4.1, ADR 0022): POST /api/v1/items/{item}/image, the display photo of a product. Admin only (catalog.manage),
 * multipart (photo, an optional 4:3 crop in source pixels after the EXIF orientation, client_uuid), idempotent (rule 7), audited with
 * the before and after ids, three renditions never upscaled, no metadata, WebP or JPEG by catalog.images.format; a replace soft-deletes
 * the old row; catalog_version moves; the item payloads carry `image`. Every image here is built in memory with GD.
 */

beforeEach(function () {
    Storage::fake(config('catalog.images.disk'));
    config(['catalog.images.format' => 'jpeg']);
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = loginAs(makeUser($this->branch, 'admin'));
    withActor($this->admin->id);
    $this->cake = Item::factory()->product(Fulfilment::ToStock, 'slice')->create(['branch_id' => $this->branch->id, 'name_en' => 'Black forest cake', 'created_by_user_id' => $this->admin->id]);
    $this->disk = Storage::disk(config('catalog.images.disk'));
});

/** @return array{0: int, 1: int, 2: string} width, height and mime of the file behind a media URL */
function uploadedSize(string $url): array
{
    $bytes = photoFile($url);
    expect($bytes)->not->toBeNull("no file behind {$url}");
    $info = getimagesizefromstring((string) $bytes);

    return [$info[0], $info[1], $info['mime']];
}

/** @return array{0: int, 1: int, 2: int} the colour of one pixel of the file behind a media URL */
function uploadedPixel(string $url, int $x, int $y): array
{
    return imgPixel((string) photoFile($url), $x, $y);
}

it('sets a photo: 201 with the image (id, three URLs, the large size, who and when), one row the item points at, and three files', function () {
    $response = photoPost($this, $this->cake->id, imgJpeg(2400, 1800, 70));

    $response->assertCreated()
        ->assertJsonPath('data.item_id', $this->cake->id)
        ->assertJsonPath('data.image.width', 1600)
        ->assertJsonPath('data.image.height', 1200)
        ->assertJsonPath('data.image.origin', 'upload')
        ->assertJsonPath('data.image.uploaded_by.id', $this->admin->id)
        ->assertJsonPath('data.image.uploaded_by.name', $this->admin->name);
    $image = $response->json('data.image');
    $row = ItemImage::query()->sole();

    expect(array_keys($image))->toBe(['id', 'thumb', 'card', 'large', 'width', 'height', 'origin', 'uploaded_at', 'uploaded_by'])
        ->and($image['id'])->toBe($row->id)
        ->and($image['thumb'])->toBe("/api/v1/media/items/{$row->sha256}/thumb.jpg")
        ->and($image['card'])->toBe("/api/v1/media/items/{$row->sha256}/card.jpg")
        ->and($image['large'])->toBe("/api/v1/media/items/{$row->sha256}/large.jpg")
        ->and($image['uploaded_at'])->toBe($row->created_at?->toIso8601String())
        ->and(uploadedSize($image['thumb']))->toBe([320, 240, 'image/jpeg'])
        ->and(uploadedSize($image['card']))->toBe([800, 600, 'image/jpeg'])
        ->and(uploadedSize($image['large']))->toBe([1600, 1200, 'image/jpeg'])
        ->and($row->sha256)->toBe(hash('sha256', (string) photoFile($image['large'])))
        ->and($row->bytes)->toBe(strlen((string) photoFile($image['thumb'])) + strlen((string) photoFile($image['card'])) + strlen((string) photoFile($image['large'])))
        ->and([$row->item_id, $row->branch_id, $row->disk, $row->mime, $row->origin])->toBe([$this->cake->id, $this->branch->id, 'media', 'image/jpeg', 'upload'])
        ->and($row->key_prefix)->toBe('items/'.substr($row->sha256, 0, 2).'/'.$row->sha256)
        ->and($row->uploaded_by_user_id)->toBe($this->admin->id)
        ->and($this->cake->fresh()?->image_id)->toBe($row->id)
        ->and($this->disk->allFiles())->toEqualCanonicalizing(["{$row->key_prefix}/thumb.jpg", "{$row->key_prefix}/card.jpg", "{$row->key_prefix}/large.jpg"]);
})->group('module:catalog', 'feature');

it('never upscales: a 1000 x 750 crop keeps 1000 x 750 as large, thumb and card keep their sizes', function () {
    $response = photoPost($this, $this->cake->id, imgJpeg(1200, 900), photoCrop(100, 75, 1000, 750))->assertCreated();

    expect(uploadedSize($response->json('data.image.large')))->toBe([1000, 750, 'image/jpeg'])
        ->and(uploadedSize($response->json('data.image.card')))->toBe([800, 600, 'image/jpeg'])
        ->and(uploadedSize($response->json('data.image.thumb')))->toBe([320, 240, 'image/jpeg'])
        ->and([$response->json('data.image.width'), $response->json('data.image.height')])->toBe([1000, 750]);
})->group('module:catalog', 'feature');

it('frames the photo by the crop given, snapped to exact 4:3, or by the largest centred 4:3 rectangle; the audit row records it', function () {
    // 1600 x 1200, blue with a red 400 x 400 square in the top-left corner: the top-left 800 x 600 is red in its own top-left
    $given = photoPost($this, $this->cake->id, imgJpeg(1600, 1200), photoCrop(0, 0, 801, 601))->assertCreated();
    $crop = fn (): ?array => AuditLog::query()->where('action', 'catalog.item.image_set')->latest('id')->firstOrFail()->after['crop'] ?? null;

    expect($crop())->toBe(['x' => 0, 'y' => 0, 'width' => 800, 'height' => 600], 'one pixel over 4:3 each way: snapped')
        ->and(imgIsRed(uploadedPixel($given->json('data.image.card'), 100, 100)))->toBeTrue()
        ->and(imgIsRed(uploadedPixel($given->json('data.image.card'), 700, 500)))->toBeFalse();

    // 2000 x 1200: the centred 4:3 rectangle is 1600 x 1200 from x = 200, so the red 400 x 400 corner is cut to 200 x 400
    $centred = photoPost($this, $this->cake->id, imgJpeg(2000, 1200))->assertCreated();
    expect($crop())->toBe(['x' => 200, 'y' => 0, 'width' => 1600, 'height' => 1200])
        ->and(imgIsRed(uploadedPixel($centred->json('data.image.large'), 100, 100)))->toBeTrue()
        ->and(imgIsRed(uploadedPixel($centred->json('data.image.large'), 300, 100)))->toBeFalse();
})->group('module:catalog', 'feature');

it('refuses a crop outside the image or not 4:3 with catalog.image.crop_invalid, and a crop with some of its fields with validation.failed', function () {
    photoPost($this, $this->cake->id, imgJpeg(1600, 1200), photoCrop(900, 0, 800, 600))
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.image.crop_invalid');
    photoPost($this, $this->cake->id, imgJpeg(1600, 1200), photoCrop(0, 0, 900, 900))
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.image.crop_invalid');
    photoPost($this, $this->cake->id, imgJpeg(1600, 1200), ['crop_x' => 0, 'crop_y' => 0])
        ->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');

    expect(ItemImage::query()->withTrashed()->count())->toBe(0)
        ->and($this->disk->allFiles())->toBe([]);
})->group('module:catalog', 'feature');

it('refuses a crop under 800 x 600 with catalog.image.too_small, in both languages, with the sizes', function () {
    $response = photoPost($this, $this->cake->id, imgJpeg(1200, 900), photoCrop(0, 0, 799, 600));

    $response->assertStatus(422)
        ->assertJsonPath('message_key', 'catalog.image.too_small')
        ->assertJsonPath('min_width', 800)
        ->assertJsonPath('min_height', 600)
        ->assertJsonPath('width', 796)
        ->assertJsonPath('height', 597);
    expect($response->json('message_en'))->toBe(trans('catalog.image.too_small', [], 'en'))->not->toBe('catalog.image.too_small')
        ->and($response->json('message_am'))->toBe(trans('catalog.image.too_small', [], 'am'))->not->toBe($response->json('message_en'));

    photoPost($this, $this->cake->id, imgJpeg(799, 600))->assertStatus(422)->assertJsonPath('message_key', 'catalog.image.too_small');
    photoPost($this, $this->cake->id, imgJpeg(800, 600))->assertCreated();
    expect(ItemImage::query()->count())->toBe(1);
})->group('module:catalog', 'feature');

it('refuses what is not a usable picture, each with its own key, and stores nothing', function () {
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    $jpeg = imgJpeg(1000, 750);

    photoPost($this, $this->cake->id, 'this is not a picture', name: 'notes.jpg')->assertStatus(422)->assertJsonPath('message_key', 'catalog.image.not_an_image');
    photoPost($this, $this->cake->id, substr($jpeg, 0, intdiv(strlen($jpeg), 2)))->assertStatus(422)->assertJsonPath('message_key', 'catalog.image.not_an_image');
    photoPost($this, $this->cake->id, $gif, name: 'cake.gif')->assertStatus(422)->assertJsonPath('message_key', 'catalog.image.unsupported_type');
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
        ->post("/api/v1/items/{$this->cake->id}/image", ['client_uuid' => (string) Str::uuid()])
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.image.not_an_image');

    config(['catalog.images.max_upload_bytes' => strlen($jpeg) - 1]);
    photoPost($this, $this->cake->id, $jpeg)->assertStatus(422)
        ->assertJsonPath('message_key', 'catalog.image.too_large')->assertJsonPath('max_bytes', strlen($jpeg) - 1);
    config(['catalog.images.max_upload_bytes' => 20971520, 'catalog.images.max_pixels' => 1000 * 750 - 1]);
    photoPost($this, $this->cake->id, $jpeg)->assertStatus(422)
        ->assertJsonPath('message_key', 'catalog.image.too_large')->assertJsonPath('max_pixels', 1000 * 750 - 1);

    expect(ItemImage::query()->withTrashed()->count())->toBe(0)
        ->and($this->disk->allFiles())->toBe([])
        ->and($this->cake->fresh()?->image_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'catalog.item.image_set')->count())->toBe(0);
})->group('module:catalog', 'feature');

it('turns the photo by its EXIF orientation 6 first, and reads the crop in the turned pixels', function () {
    // 1200 x 900, red 300 x 300 in its top-left; orientation 6 turns it to 900 x 1200 with the red in the top-right (x 600 to 899)
    $turned = imgInject(imgJpeg(1200, 900), imgExifApp1(6));

    $response = photoPost($this, $this->cake->id, $turned, photoCrop(100, 0, 800, 600))->assertCreated();
    $card = $response->json('data.image.card');

    expect(uploadedSize($card))->toBe([800, 600, 'image/jpeg'])
        ->and(imgIsRed(uploadedPixel($card, 700, 100)))->toBeTrue('the turned photo\'s top-right corner')
        ->and(imgIsRed(uploadedPixel($card, 100, 100)))->toBeFalse();

    // 1000 px wide fits the 1200 px of the file as stored, not the 900 px of the photo as seen
    photoPost($this, $this->cake->id, $turned, photoCrop(0, 0, 1000, 750))
        ->assertStatus(422)->assertJsonPath('message_key', 'catalog.image.crop_invalid');
})->group('module:catalog', 'feature');

it('writes no EXIF, GPS or ICC into any rendition', function () {
    $input = imgInject(imgJpeg(1200, 900), imgExifApp1(1), imgIcc());
    expect(imgSegments($input))->toContain(0xE1)->toContain(0xE2);

    foreach (ImageProcessor::canWebp() ? ['jpeg', 'webp'] : ['jpeg'] as $format) {
        config(['catalog.images.format' => $format]);
        $image = photoPost($this, $this->cake->id, $input)->assertCreated()->json('data.image');

        foreach (['thumb', 'card', 'large'] as $rendition) {
            $bytes = (string) photoFile($image[$rendition]);
            expect(str_contains($bytes, 'Exif'))->toBeFalse("{$format} {$rendition}")
                ->and(str_contains($bytes, 'ICC_PROFILE'))->toBeFalse();
            if ($format === 'jpeg') {
                expect(imgSegments($bytes))->not->toContain(0xE1)->not->toContain(0xE2)->not->toContain(0xED)->not->toContain(0xFE);
            } else {
                expect(array_intersect(photoWebpChunks($bytes), ['EXIF', 'XMP ', 'ICCP']))->toBe([]);
            }
        }
    }
})->group('module:catalog', 'feature');

it('writes JPEG or WebP as catalog.images.format says: auto is WebP where gd has it, and a forced WebP without it is refused, not faked', function () {
    config(['catalog.images.format' => 'jpeg']);
    $jpeg = photoPost($this, $this->cake->id, imgJpeg(800, 600))->assertCreated()->json('data.image');
    expect($jpeg['card'])->toEndWith('/card.jpg')
        ->and(uploadedSize($jpeg['card']))->toBe([800, 600, 'image/jpeg'])
        ->and(ItemImage::query()->latest('id')->firstOrFail()->mime)->toBe('image/jpeg');

    config(['catalog.images.format' => 'auto']);
    $auto = photoPost($this, $this->cake->id, imgJpeg(804, 603))->assertCreated()->json('data.image');
    expect($auto['card'])->toEndWith(ImageProcessor::canWebp() ? '/card.webp' : '/card.jpg');

    config(['catalog.images.format' => 'webp']);
    $webp = photoPost($this, $this->cake->id, imgJpeg(808, 606));
    if (! ImageProcessor::canWebp()) {
        $webp->assertStatus(503)->assertJsonPath('message_key', 'catalog.image.unavailable');
        expect(ItemImage::query()->count())->toBe(2);

        return;
    }
    $webp->assertCreated();
    expect($webp->json('data.image.card'))->toEndWith('/card.webp')
        ->and(uploadedSize($webp->json('data.image.card')))->toBe([800, 600, 'image/webp'])
        ->and(ItemImage::query()->latest('id')->firstOrFail()->mime)->toBe('image/webp');
})->group('module:catalog', 'feature');

it('replays: the same Idempotency-Key answers the stored response, the same client_uuid under a new key answers its row; one row, one set of files', function () {
    $key = (string) Str::uuid();
    $uuid = (string) Str::uuid();
    $bytes = imgJpeg(1000, 750);

    $first = photoPost($this, $this->cake->id, $bytes, ['client_uuid' => $uuid], $key)->assertCreated();
    $replay = photoPost($this, $this->cake->id, $bytes, ['client_uuid' => $uuid], $key);
    $again = photoPost($this, $this->cake->id, $bytes, ['client_uuid' => $uuid]);

    expect($replay->getStatusCode())->toBe(201)
        ->and($replay->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and($replay->json())->toEqual($first->json())
        ->and($again->getStatusCode())->toBe(200)
        ->and($again->json('data'))->toEqual($first->json('data'))
        ->and(ItemImage::query()->withTrashed()->count())->toBe(1)
        ->and($this->disk->allFiles())->toHaveCount(3)
        ->and(AuditLog::query()->where('action', 'catalog.item.image_set')->count())->toBe(1);

    // the same key with another picture is a reuse, not a replay
    photoPost($this, $this->cake->id, imgJpeg(1004, 753), ['client_uuid' => $uuid], $key)
        ->assertStatus(422)->assertJsonPath('message_key', 'idempotency.key_reused');
})->group('module:catalog', 'feature');

it('answers 403 to every role but the admin, and stores nothing', function () {
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        photoPost($this, $this->cake->id, imgJpeg(800, 600))->assertForbidden();
        $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/items/{$this->cake->id}/image")->assertForbidden();
    }

    expect(ItemImage::query()->withTrashed()->count())->toBe(0);
})->group('module:catalog', 'feature');

it('answers 404 for another branch\'s item, a deleted item and an unknown id, and 422 catalog.image.not_a_product for an ingredient', function () {
    $theirs = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => Branch::factory()->create()->id]);
    $gone = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id]);
    $gone->delete();
    $flour = Item::factory()->ingredient('kg')->create(['branch_id' => $this->branch->id]);

    photoPost($this, $theirs->id, imgJpeg(800, 600))->assertNotFound();
    photoPost($this, $gone->id, imgJpeg(800, 600))->assertNotFound();
    photoPost($this, 987654, imgJpeg(800, 600))->assertNotFound();
    photoPost($this, $flour->id, imgJpeg(800, 600))->assertStatus(422)->assertJsonPath('message_key', 'catalog.image.not_a_product');

    expect(ItemImage::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($this->disk->allFiles())->toBe([]);
})->group('module:catalog', 'feature');

it('replaces: a new row, the old one soft-deleted and its URLs gone, the audit row names both ids, and catalog_version moves each time', function () {
    $version = fn (): int => (int) app(Items::class)->menuFingerprint()['catalog_version'];
    $start = $version();
    $first = photoPost($this, $this->cake->id, imgJpeg(1000, 750))->assertCreated()->json('data.image');
    $afterFirst = $version();
    $second = photoPost($this, $this->cake->id, imgJpeg(1200, 900))->assertCreated()->json('data.image');

    $old = ItemImage::query()->withTrashed()->findOrFail($first['id']);
    $audits = AuditLog::query()->where('action', 'catalog.item.image_set')->orderBy('id')->get();

    expect($old->trashed())->toBeTrue()
        ->and(ItemImage::query()->sole()->id)->toBe($second['id'])
        ->and($this->cake->fresh()?->image_id)->toBe($second['id'])
        ->and($afterFirst)->toBeGreaterThan($start)
        ->and($version())->toBeGreaterThan($afterFirst)
        ->and($audits)->toHaveCount(2)
        ->and($audits[0]->subject_id)->toBe($this->cake->id)
        ->and($audits[0]->actor_id)->toBe($this->admin->id)
        ->and(array_intersect_key($audits[0]->after ?? [], array_flip(['item_id', 'before_image_id', 'after_image_id', 'width', 'height', 'origin'])))
        ->toEqual(['item_id' => $this->cake->id, 'before_image_id' => null, 'after_image_id' => $first['id'], 'width' => 1000, 'height' => 750, 'origin' => 'upload'])
        ->and($audits[1]->after['before_image_id'] ?? null)->toBe($first['id'])
        ->and($audits[1]->after['after_image_id'] ?? null)->toBe($second['id'])
        ->and(photoFile($first['large']))->not->toBeNull('the old files wait for the prune (6.1b)');

    foreach (['thumb', 'card', 'large'] as $rendition) {
        $this->get($first[$rendition])->assertNotFound();
        $this->get($second[$rendition])->assertOk();
    }
})->group('module:catalog', 'feature');

it('puts image on the item payloads: a summary in the list, the detail on the product, null without a photo; PATCH never touches it', function () {
    $tart = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'name_en' => 'Apple tart']);
    $image = photoPost($this, $this->cake->id, imgJpeg(1000, 750))->assertCreated()->json('data.image');
    $summary = ['thumb' => $image['thumb'], 'card' => $image['card'], 'large' => $image['large'], 'width' => 1000, 'height' => 750];

    $list = collect($this->getJson('/api/v1/items?kind=product')->assertOk()->json('data'))->keyBy('id');
    expect($list[$this->cake->id]['image'])->toBe($summary)
        ->and($list[$tart->id]['image'])->toBeNull();

    $this->getJson("/api/v1/items/{$this->cake->id}")->assertOk()->assertJsonPath('image', $image);
    $this->getJson("/api/v1/items/{$tart->id}")->assertOk()->assertJsonPath('image', null);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/items/{$this->cake->id}", ['name_en' => 'Black forest gateau', 'image' => null, 'image_id' => null])
        ->assertOk()->assertJsonPath('image', $summary);
    expect($this->cake->fresh()?->image_id)->toBe($image['id']);
})->group('module:catalog', 'feature');
