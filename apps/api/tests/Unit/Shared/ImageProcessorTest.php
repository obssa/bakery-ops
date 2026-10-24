<?php

declare(strict_types=1);

use App\Shared\Files\FilesError;
use App\Shared\Files\ImageProcessor;

/*
 * Step 4.2a: ImageProcessor decodes, applies the EXIF orientation, shrinks to a longest side of 1600 px (never enlarging), flattens alpha
 * onto white and re-encodes as JPEG at quality 82, which writes no metadata; the result is re-read before it is returned. No database.
 */

/** The message_key a refusal carries, or null when the call succeeded. */
function imgRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (FilesError $e) {
        return $e->messageKey;
    }

    return null;
}

// orientation => [width, height of the 60x40 source after the correction, where the red top-left corner of the source ends up]
dataset('orientations', [
    1 => [1, 60, 40, 'TL'],
    2 => [2, 60, 40, 'TR'],
    3 => [3, 60, 40, 'BR'],
    4 => [4, 60, 40, 'BL'],
    5 => [5, 40, 60, 'TL'],
    6 => [6, 40, 60, 'TR'],
    7 => [7, 40, 60, 'BR'],
    8 => [8, 40, 60, 'BL'],
]);

it('applies EXIF orientation 1 to 8 (with the extension and with the pure-PHP reader)', function (int $orientation, int $w, int $h, string $corner) {
    $input = imgInject(imgJpeg(60, 40), imgExifApp1($orientation));

    foreach ([true, false] as $preferExtension) {
        $out = (new ImageProcessor(preferExtension: $preferExtension))->process($input);
        $points = ['TL' => [4, 4], 'TR' => [$w - 5, 4], 'BR' => [$w - 5, $h - 5], 'BL' => [4, $h - 5]];

        expect([$out->width, $out->height])->toBe([$w, $h])
            ->and(imgIsRed(imgPixel($out->bytes, ...$points[$corner])))->toBeTrue("orientation {$orientation}: red corner at {$corner}")
            ->and(imgIsRed(imgPixel($out->bytes, ...$points[['TL' => 'BR', 'TR' => 'BL', 'BR' => 'TL', 'BL' => 'TR'][$corner]])))->toBeFalse();
    }
})->with('orientations')->group('unit', 'module:shared');

it('leaves no EXIF, GPS or ICC block and writes a JPEG', function () {
    $input = imgInject(imgJpeg(300, 200), imgExifApp1(1), imgIcc());
    expect(imgSegments($input))->toContain(0xE1)->toContain(0xE2);

    $out = (new ImageProcessor)->process($input);

    expect(imgSegments($out->bytes))->not->toContain(0xE1)->not->toContain(0xE2)->not->toContain(0xED)->not->toContain(0xFE)
        ->and(str_contains($out->bytes, 'Exif'))->toBeFalse()
        ->and(str_contains($out->bytes, 'ICC_PROFILE'))->toBeFalse()
        ->and($out->mime)->toBe('image/jpeg')
        ->and(getimagesizefromstring($out->bytes)['mime'])->toBe('image/jpeg');
});

it('shrinks the longest side to 1600 keeping the aspect, and never enlarges', function (int $w, int $h, int $ew, int $eh) {
    $out = (new ImageProcessor)->process(imgJpeg($w, $h));

    expect([$out->width, $out->height])->toBe([$ew, $eh])
        ->and(array_slice(getimagesizefromstring($out->bytes), 0, 2))->toBe([$ew, $eh]);
})->with([
    'landscape 4000x3000' => [4000, 3000, 1600, 1200],
    'portrait 3000x4000' => [3000, 4000, 1200, 1600],
    'exactly 1600' => [1600, 900, 1600, 900],
    'small stays small' => [800, 600, 800, 600],
])->group('unit', 'module:shared');

it('honours the configured edge and quality', function () {
    $small = (new ImageProcessor(maxEdge: 400))->process(imgJpeg(1000, 500));
    $low = (new ImageProcessor(quality: 20))->process(imgJpeg(900, 700));
    $high = (new ImageProcessor(quality: 82))->process(imgJpeg(900, 700));

    expect([$small->width, $small->height])->toBe([400, 200])
        ->and(strlen($low->bytes))->toBeLessThan(strlen($high->bytes));
})->group('unit', 'module:shared');

it('flattens transparency onto white, fully and partly', function (int $alpha) {
    $out = (new ImageProcessor)->process(imgPng(120, 80, $alpha));
    [$r, $g, $b] = imgPixel($out->bytes, 10, 10);

    expect(getimagesizefromstring($out->bytes)['mime'])->toBe('image/jpeg')
        ->and($r)->toBeGreaterThan(110)->and($g)->toBeGreaterThan(110)->and($b)->toBeGreaterThan(110);
    if ($alpha === 127) {
        expect($r)->toBeGreaterThan(240)->and($g)->toBeGreaterThan(240)->and($b)->toBeGreaterThan(240);
    }
})->with([127, 64])->group('unit', 'module:shared');

it('reads PNG without alpha and WebP when gd has it', function () {
    $png = (new ImageProcessor)->process(imgPng(50, 50));
    expect($png->mime)->toBe('image/jpeg');

    if (! (gd_info()['WebP Support'] ?? false)) {
        return; // the doctor reports it; the processor answers files.unsupported_type
    }
    $image = imagecreatetruecolor(64, 48);
    ob_start();
    imagewebp($image, null, 80);
    $webp = (string) ob_get_clean();

    $out = (new ImageProcessor)->process($webp);
    expect([$out->width, $out->height])->toBe([64, 48]);
})->group('unit', 'module:shared');

it('refuses what is not a usable image, each with its own key', function () {
    $jpeg = imgJpeg(300, 200);
    $png = imgPng(100, 100);
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    $cases = [
        'a sentence' => ['this is not an image', 'files.not_an_image'],
        'empty' => ['', 'files.not_an_image'],
        'truncated jpeg' => [substr($jpeg, 0, intdiv(strlen($jpeg), 2)), 'files.truncated'],
        'truncated png' => [substr($png, 0, intdiv(strlen($png), 2)), 'files.truncated'],
        'php after the jpeg' => [$jpeg."<?php system(\$_GET['c']); ?>", 'files.not_an_image'],
        'php after the png' => [$png.'<?php echo 1; ?>', 'files.not_an_image'],
        'gif' => [$gif, 'files.unsupported_type'],
    ];

    foreach ($cases as $name => [$bytes, $key]) {
        expect(imgRefusal(fn () => (new ImageProcessor)->process($bytes)))->toBe($key, $name);
    }
})->group('unit', 'module:shared');

it('refuses an image over max_pixels from its header, before decoding it', function () {
    $big = imagecreatetruecolor(5000, 5000);
    ob_start();
    imagejpeg($big, null, 5);
    $bytes = (string) ob_get_clean();

    expect(imgRefusal(fn () => (new ImageProcessor(maxPixels: 24_000_000))->process($bytes)))->toBe('files.too_many_pixels')
        ->and(imgRefusal(fn () => (new ImageProcessor(maxPixels: 30_000_000, maxEdge: 200))->process($bytes)))->toBeNull();
})->group('unit', 'module:shared');
