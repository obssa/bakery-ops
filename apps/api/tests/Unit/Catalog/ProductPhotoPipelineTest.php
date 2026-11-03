<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Support\ProductPhotoPipeline;
use App\Shared\Files\ImageProcessor;

/*
 * Step 6.1a (phase-6 plan 4.1): ProductPhotoPipeline makes the three renditions of a product photo from one upload. Sizes: thumb
 * 320 x 240, card 800 x 600, large 1600 x 1200 or the crop's own size, never upscaled. Formats: WebP when asked or when `auto` meets a
 * gd with WebP, JPEG when asked or when gd has no WebP; a forced WebP without it is catalog.image.unavailable, never a silent JPEG. The
 * sha256 is of the large bytes; bytes is the three together. No database, no container: the pipeline is built with plain values.
 */

/** The message_key of a CatalogError the call throws, or null. */
function pipelineRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (CatalogError $e) {
        return $e->messageKey;
    }

    return null;
}

/** @return array{0: int, 1: int, 2: string} width, height and mime of encoded bytes */
function pipelineSize(string $bytes): array
{
    $info = getimagesizefromstring($bytes);

    return [$info[0], $info[1], $info['mime']];
}

it('makes thumb 320 x 240, card 800 x 600 and large 1600 x 1200 from a 2400 x 1800 photo, in one format, named by the large bytes', function () {
    $photo = (new ProductPhotoPipeline(format: 'jpeg'))->make(imgJpeg(2400, 1800, 70));

    expect(pipelineSize($photo->renditions['thumb']))->toBe([320, 240, 'image/jpeg'])
        ->and(pipelineSize($photo->renditions['card']))->toBe([800, 600, 'image/jpeg'])
        ->and(pipelineSize($photo->renditions['large']))->toBe([1600, 1200, 'image/jpeg'])
        ->and([$photo->width, $photo->height, $photo->mime, $photo->extension])->toBe([1600, 1200, 'image/jpeg', 'jpg'])
        ->and($photo->sha256)->toBe(hash('sha256', $photo->renditions['large']))
        ->and($photo->bytes)->toBe(strlen($photo->renditions['thumb']) + strlen($photo->renditions['card']) + strlen($photo->renditions['large']))
        ->and($photo->keyPrefix())->toBe('items/'.substr($photo->sha256, 0, 2).'/'.$photo->sha256)
        ->and($photo->crop->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => 2400, 'height' => 1800]);
})->group('unit', 'module:catalog');

it('never upscales: a 1000 x 750 crop keeps 1000 x 750 as large, and thumb and card keep their sizes', function () {
    $photo = (new ProductPhotoPipeline(format: 'jpeg'))->make(imgJpeg(1200, 900), ['x' => 100, 'y' => 75, 'width' => 1000, 'height' => 750]);
    $exact = (new ProductPhotoPipeline(format: 'jpeg'))->make(imgJpeg(800, 600));

    expect(pipelineSize($photo->renditions['large']))->toBe([1000, 750, 'image/jpeg'])
        ->and(pipelineSize($photo->renditions['card']))->toBe([800, 600, 'image/jpeg'])
        ->and(pipelineSize($photo->renditions['thumb']))->toBe([320, 240, 'image/jpeg'])
        ->and([$photo->width, $photo->height])->toBe([1000, 750])
        ->and(pipelineSize($exact->renditions['large']))->toBe([800, 600, 'image/jpeg'])
        ->and(pipelineSize($exact->renditions['card']))->toBe([800, 600, 'image/jpeg']);
})->group('unit', 'module:catalog');

it('refuses a crop under 800 x 600 with catalog.image.too_small and the sizes, and accepts one of exactly 800 x 600', function () {
    $pipeline = new ProductPhotoPipeline(format: 'jpeg');

    try {
        $pipeline->make(imgJpeg(1200, 900), ['x' => 0, 'y' => 0, 'width' => 799, 'height' => 600]);
        $refusal = null;
    } catch (CatalogError $e) {
        $refusal = $e;
    }

    expect($refusal?->messageKey)->toBe('catalog.image.too_small')
        ->and($refusal?->extra)->toBe(['min_width' => 800, 'min_height' => 600, 'width' => 796, 'height' => 597], 'the crop as snapped to exact 4:3')
        ->and(pipelineRefusal(fn () => $pipeline->make(imgJpeg(799, 600))))->toBe('catalog.image.too_small')
        ->and(pipelineRefusal(fn () => $pipeline->make(imgJpeg(1200, 900), ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 600])))->toBeNull()
        ->and(pipelineRefusal(fn () => (new ProductPhotoPipeline(format: 'jpeg', minWidth: 400, minHeight: 300))->make(imgJpeg(400, 300))))->toBeNull('the minimum is configured');
})->group('unit', 'module:catalog');

it('writes JPEG when told to, and WebP when told to or by auto wherever gd has it; a forced WebP without it is catalog.image.unavailable', function () {
    $jpeg = (new ProductPhotoPipeline(format: 'jpeg'))->make(imgJpeg(800, 600));
    expect([$jpeg->mime, $jpeg->extension])->toBe(['image/jpeg', 'jpg']);

    $auto = (new ProductPhotoPipeline(format: 'auto'))->make(imgJpeg(800, 600));
    if (! ImageProcessor::canWebp()) {
        expect([$auto->mime, $auto->extension])->toBe(['image/jpeg', 'jpg'], 'auto falls back to JPEG')
            ->and(pipelineRefusal(fn () => (new ProductPhotoPipeline(format: 'webp'))->make(imgJpeg(800, 600))))->toBe('catalog.image.unavailable');

        return;
    }
    $webp = (new ProductPhotoPipeline(format: 'webp'))->make(imgJpeg(800, 600));

    expect([$auto->mime, $auto->extension])->toBe(['image/webp', 'webp'])
        ->and([$webp->mime, $webp->extension])->toBe(['image/webp', 'webp'])
        ->and(pipelineSize($webp->renditions['thumb']))->toBe([320, 240, 'image/webp'])
        ->and(pipelineSize($webp->renditions['large']))->toBe([800, 600, 'image/webp']);
})->group('unit', 'module:catalog');

it('tells the upload\'s refusals in Catalog\'s words', function () {
    $pipeline = new ProductPhotoPipeline(format: 'jpeg', maxBytes: 4000, maxPixels: 2_000_000);
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    $jpeg = imgJpeg(1000, 750);

    expect(pipelineRefusal(fn () => $pipeline->make('not a picture')))->toBe('catalog.image.not_an_image')
        ->and(pipelineRefusal(fn () => $pipeline->make(substr(imgJpeg(800, 600), 0, 1500))))->toBe('catalog.image.not_an_image', 'a cut file')
        ->and(pipelineRefusal(fn () => $pipeline->make($gif)))->toBe('catalog.image.unsupported_type')
        ->and(strlen($jpeg))->toBeGreaterThan(4000)
        ->and(pipelineRefusal(fn () => $pipeline->make($jpeg)))->toBe('catalog.image.too_large')
        ->and(pipelineRefusal(fn () => $pipeline->make(imgJpeg(2000, 1500, 5))))->toBe('catalog.image.too_large', 'over the pixel cap')
        ->and(pipelineRefusal(fn () => $pipeline->make($jpeg, ['x' => 0, 'y' => 0, 'width' => 900, 'height' => 900])))->toBe('catalog.image.too_large', 'the bytes are checked before the crop');
})->group('unit', 'module:catalog');
