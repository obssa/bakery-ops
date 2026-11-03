<?php

declare(strict_types=1);

use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Support\CropRect;

/*
 * Step 6.1a (phase-6 plan 4.1 step 2): the 4:3 crop of a product photo, in source pixels after the EXIF orientation. Exact 4:3 is
 * 4k x 3k. No crop: the largest centred one. A given crop must lie inside the image and be within 1 px of 4:3 (|4h - 3w| <= 4), and is
 * snapped to the largest exact 4:3 rectangle inside it, centred in it. No database.
 */

/** The message_key of a CatalogError the call throws, or null. */
function cropRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (CatalogError $e) {
        return $e->messageKey;
    }

    return null;
}

it('takes the largest centred 4:3 rectangle when no crop is given', function (int $w, int $h, array $expected) {
    expect(CropRect::centred($w, $h)->toArray())->toBe($expected);
})->with([
    'already 4:3' => [2400, 1800, ['x' => 0, 'y' => 0, 'width' => 2400, 'height' => 1800]],
    'wider: the sides go' => [2000, 1200, ['x' => 200, 'y' => 0, 'width' => 1600, 'height' => 1200]],
    'taller: top and bottom go' => [900, 1200, ['x' => 0, 'y' => 262, 'width' => 900, 'height' => 675]],
    '3:2 with a remainder' => [3000, 2000, ['x' => 168, 'y' => 1, 'width' => 2664, 'height' => 1998]],
    'a phone portrait' => [1800, 2400, ['x' => 0, 'y' => 525, 'width' => 1800, 'height' => 1350]],
])->group('unit', 'module:catalog');

it('keeps an exact 4:3 crop as given', function () {
    expect(CropRect::given(100, 50, 1000, 750, 1600, 1200)->toArray())->toBe(['x' => 100, 'y' => 50, 'width' => 1000, 'height' => 750])
        ->and(CropRect::given(0, 0, 1600, 1200, 1600, 1200)->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => 1600, 'height' => 1200]);
})->group('unit', 'module:catalog');

it('snaps a crop within 1 px of 4:3 to the largest exact 4:3 inside it, centred in it', function (array $given, array $expected) {
    expect(CropRect::given(...[...$given, 1600, 1200])->toArray())->toBe($expected);
})->with([
    'one pixel wider' => [[10, 10, 1001, 750], ['x' => 10, 'y' => 10, 'width' => 1000, 'height' => 750]],
    'one pixel taller' => [[10, 10, 1000, 751], ['x' => 10, 'y' => 10, 'width' => 1000, 'height' => 750]],
    'one pixel narrower' => [[10, 10, 999, 750], ['x' => 11, 'y' => 11, 'width' => 996, 'height' => 747]],
    'one pixel shorter' => [[10, 10, 1000, 749], ['x' => 12, 'y' => 11, 'width' => 996, 'height' => 747]],
    'both a pixel more' => [[0, 0, 801, 601], ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 600]],
])->group('unit', 'module:catalog');

it('refuses a crop outside the image or not within 1 px of 4:3 with catalog.image.crop_invalid', function (array $given) {
    expect(cropRefusal(fn () => CropRect::given(...[...$given, 1600, 1200])))->toBe('catalog.image.crop_invalid');
})->with([
    'past the right edge' => [[900, 0, 800, 600]],
    'past the bottom edge' => [[0, 700, 800, 600]],
    'a negative x' => [[-1, 0, 800, 600]],
    'a negative y' => [[0, -4, 800, 600]],
    'no width' => [[0, 0, 0, 0]],
    'square' => [[0, 0, 800, 800]],
    '16:9' => [[0, 0, 1600, 900]],
    'two pixels short of 4:3' => [[0, 0, 800, 598]],
])->group('unit', 'module:catalog');

it('says whether a crop reaches the minimum, and fits a rendition without ever enlarging', function () {
    $small = CropRect::given(0, 0, 1000, 750, 1600, 1200);
    $big = CropRect::given(0, 0, 1600, 1200, 1600, 1200);

    expect($small->isAtLeast(800, 600))->toBeTrue()
        ->and(CropRect::given(0, 0, 796, 597, 1600, 1200)->isAtLeast(800, 600))->toBeFalse()
        ->and($small->fit(320, 240))->toBe([320, 240])
        ->and($small->fit(800, 600))->toBe([800, 600])
        ->and($small->fit(1600, 1200))->toBe([1000, 750], 'the crop\'s own size, never upscaled')
        ->and($big->fit(1600, 1200))->toBe([1600, 1200]);
})->group('unit', 'module:catalog');
