<?php

declare(strict_types=1);

use App\Shared\Files\FilesError;
use App\Shared\Files\ImageProcessor;

/*
 * Step 6.1a (phase-6 plan 4.1): the public steps split out of ImageProcessor::process() for the product photo pipeline. load() refuses
 * what process() refuses and answers the decoded image the right way up, transparency kept; resample() draws a rectangle onto white in
 * one resample; encodeScrubbed() writes a JPEG or a WebP without metadata and proves it. process() keeps its own tests
 * (ImageProcessorTest), unchanged. No database.
 */

/** The message_key a refusal carries, or null when the call succeeded. */
function stepRefusal(Closure $call): ?string
{
    try {
        $call();
    } catch (FilesError $e) {
        return $e->messageKey;
    }

    return null;
}

/** @return list<string> the four-character ids of a RIFF/WEBP file's chunks, in order */
function stepWebpChunks(string $bytes): array
{
    $chunks = [];
    $i = 12;
    while ($i + 8 <= strlen($bytes)) {
        $chunks[] = substr($bytes, $i, 4);
        $size = unpack('V', substr($bytes, $i + 4, 4))[1];
        $i += 8 + $size + ($size % 2);
    }

    return $chunks;
}

it('load() decodes and turns the image the right way up (EXIF orientation 6), with the extension and with the pure-PHP reader', function () {
    $input = imgInject(imgJpeg(60, 40), imgExifApp1(6));

    foreach ([true, false] as $preferExtension) {
        $image = (new ImageProcessor(preferExtension: $preferExtension))->load($input);
        $c = imagecolorsforindex($image, imagecolorat($image, 35, 4));

        expect([imagesx($image), imagesy($image)])->toBe([40, 60])
            ->and(imageistruecolor($image))->toBeTrue()
            ->and(imgIsRed([$c['red'], $c['green'], $c['blue']]))->toBeTrue('the red top-left corner is now top-right');
    }
})->group('unit', 'module:shared');

it('load() keeps transparency for resample() to flatten, which draws it onto white', function () {
    $processor = new ImageProcessor;
    $image = $processor->load(imgPng(40, 30, 127));
    $before = imagecolorsforindex($image, imagecolorat($image, 5, 5));

    $flat = $processor->resample($image, 0, 0, 40, 30, 40, 30);
    $after = imagecolorsforindex($flat, imagecolorat($flat, 5, 5));

    expect($before['alpha'])->toBe(127)
        ->and([$after['red'], $after['green'], $after['blue'], $after['alpha']])->toBe([255, 255, 255, 0]);
})->group('unit', 'module:shared');

it('load() refuses what process() refuses, each with its own key, before decoding what the header already rules out', function () {
    $jpeg = imgJpeg(300, 200);
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    $cases = [
        'a sentence' => [new ImageProcessor, 'this is not an image', 'files.not_an_image'],
        'empty' => [new ImageProcessor, '', 'files.not_an_image'],
        'truncated jpeg' => [new ImageProcessor, substr($jpeg, 0, intdiv(strlen($jpeg), 2)), 'files.truncated'],
        'php after the jpeg' => [new ImageProcessor, $jpeg.'<?php echo 1; ?>', 'files.not_an_image'],
        'gif' => [new ImageProcessor, $gif, 'files.unsupported_type'],
        'over the pixel cap' => [new ImageProcessor(maxPixels: 300 * 200 - 1), $jpeg, 'files.too_many_pixels'],
        'over the byte cap' => [new ImageProcessor(maxBytes: strlen($jpeg) - 1), $jpeg, 'files.too_large'],
        'fits both caps' => [new ImageProcessor(maxPixels: 300 * 200, maxBytes: strlen($jpeg)), $jpeg, null],
    ];

    foreach ($cases as $name => [$processor, $bytes, $key]) {
        expect(stepRefusal(fn () => $processor->load($bytes)))->toBe($key, $name);
    }
})->group('unit', 'module:shared');

it('resample() draws one rectangle of the source at the size asked', function () {
    $processor = new ImageProcessor;
    // 300 x 200, blue with a red 66 x 66 square in the top-left corner
    $image = $processor->load(imgJpeg(300, 200));

    $red = $processor->resample($image, 0, 0, 60, 60, 30, 30);
    $blue = $processor->resample($image, 100, 50, 200, 150, 80, 60);
    $c = fn ($img, int $x, int $y): array => array_values(array_slice(imagecolorsforindex($img, imagecolorat($img, $x, $y)), 0, 3));

    expect([imagesx($red), imagesy($red)])->toBe([30, 30])
        ->and(imgIsRed($c($red, 2, 2)))->toBeTrue()->and(imgIsRed($c($red, 27, 27)))->toBeTrue()
        ->and([imagesx($blue), imagesy($blue)])->toBe([80, 60])
        ->and(imgIsRed($c($blue, 2, 2)))->toBeFalse()->and($c($blue, 40, 30)[2])->toBeGreaterThan(150);
})->group('unit', 'module:shared');

it('encodeScrubbed() writes a JPEG with nothing but its JFIF header, at the quality asked', function () {
    $processor = new ImageProcessor;
    $image = $processor->load(imgInject(imgJpeg(320, 240), imgExifApp1(1), imgIcc()));

    $out = $processor->encodeScrubbed($image, 'jpeg', 85);
    $low = $processor->encodeScrubbed($image, 'jpeg', 20);

    expect($out->mime)->toBe('image/jpeg')
        ->and([$out->width, $out->height])->toBe([320, 240])
        ->and(getimagesizefromstring($out->bytes)['mime'])->toBe('image/jpeg')
        ->and(imgSegments($out->bytes))->not->toContain(0xE1)->not->toContain(0xE2)->not->toContain(0xED)->not->toContain(0xFE)
        ->and(str_contains($out->bytes, 'Exif'))->toBeFalse()
        ->and(str_contains($out->bytes, 'ICC_PROFILE'))->toBeFalse()
        ->and(strlen($low->bytes))->toBeLessThan(strlen($out->bytes));
})->group('unit', 'module:shared');

it('encodeScrubbed() writes a WebP without EXIF, XMP or ICC chunks when gd has WebP, and refuses with files.unavailable when it has not', function () {
    $processor = new ImageProcessor;
    $image = $processor->load(imgJpeg(320, 240));

    if (! ImageProcessor::canWebp()) {
        expect(stepRefusal(fn () => $processor->encodeScrubbed($image, 'webp', 82)))->toBe('files.unavailable');

        return;
    }
    $out = $processor->encodeScrubbed($image, 'webp', 82);

    expect($out->mime)->toBe('image/webp')
        ->and([$out->width, $out->height])->toBe([320, 240])
        ->and(substr($out->bytes, 0, 4))->toBe('RIFF')
        ->and(substr($out->bytes, 8, 4))->toBe('WEBP')
        ->and(array_intersect(stepWebpChunks($out->bytes), ['EXIF', 'XMP ', 'ICCP']))->toBe([])
        ->and(getimagesizefromstring($out->bytes)['mime'])->toBe('image/webp');
})->group('unit', 'module:shared');

it('encodeScrubbed() refuses a format it does not write', function () {
    $processor = new ImageProcessor;

    expect(stepRefusal(fn () => $processor->encodeScrubbed($processor->load(imgJpeg(40, 30)), 'gif')))->toBe('files.processing_failed');
})->group('unit', 'module:shared');
