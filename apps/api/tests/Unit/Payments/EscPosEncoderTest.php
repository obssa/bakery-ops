<?php

declare(strict_types=1);

use App\Modules\Payments\Support\Printing\Bitmap;
use App\Modules\Payments\Support\Printing\EscPos;
use Tests\Support\EscPosEmulator;

/*
 * The ESC/POS encoder (step 5.3a, phase-5 plan sections 6 and 7): ESC @, the picture in GS v 0 raster bands of at most 960 rows, a feed
 * of 4 lines and a partial cut, once per copy; the text fallback (no FreeType) prints ASCII lines in the printer's own font. The bytes are
 * checked twice: as literal bytes for the layout, and read back by the test printer (tests/Support/EscPosEmulator.php).
 */

/** A bitmap $width wide and $height high with a diagonal line and a full black first row. */
function escposPicture(int $width, int $height): Bitmap
{
    $bytes = intdiv($width, 8);
    $data = str_repeat("\xFF", $bytes);
    for ($y = 1; $y < $height; $y++) {
        $row = str_repeat("\x00", $bytes);
        $x = $y % $width;
        $row[intdiv($x, 8)] = chr(0x80 >> ($x % 8));
        $data .= $row;
    }

    return new Bitmap($width, $height, $data);
}

it('starts with ESC @, sends the picture as GS v 0 with its width in bytes and its rows, then feeds and cuts', function () {
    $picture = escposPicture(576, 40);
    $bytes = EscPos::raster($picture);

    expect(substr($bytes, 0, 2))->toBe("\x1B\x40")
        ->and(substr($bytes, 2, 8))->toBe("\x1D\x76\x30\x00".pack('v', 72).pack('v', 40))
        ->and(substr($bytes, 10, 72 * 40))->toBe($picture->data)
        ->and(substr($bytes, 10 + 72 * 40))->toBe("\x1B\x64\x04\x1D\x56\x01")
        ->and(strlen($bytes))->toBe(2 + 8 + 72 * 40 + 6);
})->group('unit', 'module:payments');

it('splits a tall picture into bands of at most 960 rows and the printer stacks them back into the same picture', function () {
    $picture = escposPicture(384, 2000);
    $printer = EscPosEmulator::parse(EscPos::raster($picture));

    expect($printer->names())->toBe(['init', 'raster', 'raster', 'raster', 'feed', 'cut'])
        ->and(array_column($printer->commands, 'rows'))->toBe([960, 960, 80])
        ->and(array_values(array_filter(array_column($printer->commands, 'bytes'))))->toBe([48, 48, 48])
        ->and($printer->bitmaps())->toHaveCount(1)
        ->and($printer->bitmaps()[0]->width)->toBe(384)
        ->and($printer->bitmaps()[0]->height)->toBe(2000)
        ->and($printer->bitmaps()[0]->data)->toBe($picture->data);
})->group('unit', 'module:payments');

it('prints each copy whole, cut after each, with one init', function () {
    $picture = escposPicture(576, 30);
    $printer = EscPosEmulator::parse(EscPos::raster($picture, 3));

    expect($printer->names())->toBe(['init', 'raster', 'feed', 'cut', 'raster', 'feed', 'cut', 'raster', 'feed', 'cut'])
        ->and($printer->bitmaps())->toHaveCount(3)
        ->and(array_map(fn (Bitmap $b): string => $b->data, $printer->bitmaps()))->toBe([$picture->data, $picture->data, $picture->data])
        ->and(EscPos::raster($picture, 0))->toBe(EscPos::raster($picture, 1));
})->group('unit', 'module:payments');

it('prints the text fallback in the printer\'s font: code page, alignment, bold, ASCII only, then feed and cut', function () {
    $bytes = EscPos::text([
        ['text' => 'Demo Bakery', 'align' => 'center', 'bold' => true],
        ['text' => 'Total                 12.50'],
        ['text' => 'Café ሻይ'],
    ], 2);
    $printer = EscPosEmulator::parse($bytes);

    expect(substr($bytes, 0, 5))->toBe("\x1B\x40\x1B\x74\x00")
        ->and($printer->lines())->toBe(['Demo Bakery', 'Total                 12.50', 'Caf? ??', 'Demo Bakery', 'Total                 12.50', 'Caf? ??'])
        ->and(array_count_values($printer->names())['cut'])->toBe(2)
        ->and($printer->bitmaps())->toBe([])
        ->and($printer->commands[2])->toBe(['cmd' => 'align', 'n' => 1])
        ->and($printer->commands[3])->toBe(['cmd' => 'bold', 'n' => 1]);
})->group('unit', 'module:payments');

it('refuses a width that is not a whole number of bytes and a picture whose bytes do not match its size', function () {
    expect(fn () => new Bitmap(570, 2, str_repeat("\x00", 142)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Bitmap(576, 2, str_repeat("\x00", 143)))->toThrow(InvalidArgumentException::class);
})->group('unit', 'module:payments');

it('counts black dots and reads one dot back', function () {
    $picture = escposPicture(64, 10);

    expect($picture->blackDots())->toBe(64 + 9)
        ->and($picture->isBlack(0, 0))->toBeTrue()
        ->and($picture->isBlack(3, 3))->toBeTrue()
        ->and($picture->isBlack(4, 3))->toBeFalse()
        ->and($picture->slice(1, 3)->height)->toBe(3)
        ->and($picture->slice(1, 3)->isBlack(1, 0))->toBeTrue();
})->group('unit', 'module:payments');

it('makes the emulator refuse a byte the encoder never sends and a stream with no cut', function () {
    expect(fn () => EscPosEmulator::parse("\x1B\x40\x07"))->toThrow(RuntimeException::class)
        ->and(fn () => EscPosEmulator::parse("\x1B\x40Hello\x0A"))->toThrow(RuntimeException::class, 'without a cut');
})->group('unit', 'module:payments');
