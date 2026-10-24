<?php

declare(strict_types=1);

use App\Shared\Files\ExifOrientation;

/*
 * Step 4.2a: the pure-PHP APP1 reader used when the exif extension is missing. It must read orientation 1 to 8 in both byte orders, find
 * the block behind an APP0, and answer 1 (upright) for anything it cannot trust.
 */

it('reads orientation 1 to 8 in both byte orders', function (int $orientation, bool $bigEndian) {
    expect(ExifOrientation::fromJpeg(imgInject(imgJpeg(40, 30), imgExifApp1($orientation, $bigEndian))))->toBe($orientation);
})->with([1, 2, 3, 4, 5, 6, 7, 8])->with([true, false])->group('unit', 'module:shared');

it('finds the EXIF block behind an APP0 and an ICC block', function () {
    $app0 = "\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
    $jpeg = imgInject(imgJpeg(40, 30), $app0, imgIcc(), imgExifApp1(6));

    expect(ExifOrientation::fromJpeg($jpeg))->toBe(6);
})->group('unit', 'module:shared');

it('answers 1 for no EXIF, a bad value, a cut block and anything that is not a JPEG', function () {
    $cut = imgExifApp1(6);
    expect(ExifOrientation::fromJpeg(imgJpeg(40, 30)))->toBe(1)
        ->and(ExifOrientation::fromJpeg(imgInject(imgJpeg(40, 30), imgExifApp1(9))))->toBe(1)
        ->and(ExifOrientation::fromJpeg(imgInject(imgJpeg(40, 30), imgExifApp1(0))))->toBe(1)
        ->and(ExifOrientation::fromJpeg("\xFF\xD8".substr($cut, 0, 14)))->toBe(1)
        ->and(ExifOrientation::fromJpeg(''))->toBe(1)
        ->and(ExifOrientation::fromJpeg(imgPng(10, 10)))->toBe(1)
        ->and(ExifOrientation::fromJpeg(str_repeat("\xFF", 64)))->toBe(1);
})->group('unit', 'module:shared');

it('agrees with the exif extension when it is loaded', function (int $orientation) {
    if (! function_exists('exif_read_data')) {
        return;
    }
    $jpeg = imgInject(imgJpeg(40, 30), imgExifApp1($orientation));
    $read = @exif_read_data('data://image/jpeg;base64,'.base64_encode($jpeg));

    expect(ExifOrientation::fromJpeg($jpeg))->toBe((int) ($read['Orientation'] ?? 1));
})->with([1, 3, 6, 8])->group('unit', 'module:shared');
