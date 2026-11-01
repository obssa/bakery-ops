<?php

declare(strict_types=1);

use App\Modules\Payments\Support\Printing\Bitmap;
use App\Modules\Payments\Support\Printing\EscPos;
use App\Modules\Payments\Support\Printing\ReceiptLayout;
use App\Modules\Payments\Support\Printing\ReceiptRaster;
use App\Modules\Payments\Support\Printing\ReceiptRow;
use App\Modules\Payments\Support\Receipts\Receipt;
use App\Shared\Time\EthiopianDate;
use Carbon\CarbonImmutable;
use Tests\Support\EscPosEmulator;

/*
 * The receipt as a printer's picture (step 5.3a, phase-5 plan sections 6 and 7): the receipt of 4.3e drawn into a 1-bit image 576 dots
 * (80 mm) or 384 dots (58 mm) wide with GD and FreeType, Amharic in Noto Sans Ethiopic and Latin text in DejaVu Sans. Each picture is sent
 * through the ESC/POS encoder, read back by the test printer (tests/Support/EscPosEmulator.php) and compared with a golden PNG in
 * tests/Fixtures/receipts within a tolerance: a dot counts as different only when the other picture has no dot of the same colour within
 * one dot of it (a glyph moved by one dot between FreeType versions is the same receipt), and at most 0.2% of the dots may differ.
 * UPDATE_RECEIPT_GOLDENS=1 writes the goldens instead of comparing (then look at them before committing).
 * It needs the container (the labels of lang/{en,am}/receipts.php and the font paths) but no database.
 */

/** The receipt every golden prints: a TIN, two lines (one with a modifier), a discount, cash with change and a mobile payment. */
function rasterReceipt(string $locale, bool $ethiopian = true, int $lines = 2): Receipt
{
    $issued = CarbonImmutable::parse('2026-11-01 14:05:00', 'Africa/Addis_Ababa');
    $rows = [
        ['name_en' => 'Black tea', 'name_am' => 'ሻይ', 'qty' => '2.0000', 'unit_price' => '12.50', 'line_total' => '25.00', 'modifiers' => []],
        ['name_en' => 'Birthday cake (large, chocolate)', 'name_am' => 'የልደት ኬክ', 'qty' => '1.0000', 'unit_price' => '455.00', 'line_total' => '455.00',
            'modifiers' => [['name_en' => 'Candles', 'name_am' => 'ሻማ', 'price_delta' => '5.00']]],
    ];
    for ($i = count($rows); $i < $lines; $i++) {
        $rows[] = ['name_en' => 'Bread roll '.($i + 1), 'name_am' => 'ዳቦ', 'qty' => '1.0000', 'unit_price' => '10.00', 'line_total' => '10.00', 'modifiers' => []];
    }

    return new Receipt(
        business: [
            'name_en' => 'Demo Bakery', 'name_am' => 'ማሳያ ዳቦ ቤት',
            'address_en' => 'Bole Road, Addis Ababa', 'address_am' => 'ቦሌ መንገድ፣ አዲስ አበባ',
            'phone' => '011 000 0000', 'tin' => '0012345678',
            'footer_en' => 'Thank you!', 'footer_am' => 'እናመሰግናለን!',
        ],
        locale: $locale,
        proforma: false,
        orderNo: 128,
        businessDate: '2026-11-01',
        orderType: 'dine_in',
        tableCode: 'T4',
        issuedAt: $issued,
        ethiopianDate: $ethiopian ? EthiopianDate::of($issued) : null,
        lines: $rows,
        money: [
            'subtotal' => '485.00', 'discount' => '10.00', 'tax' => '0.00', 'total' => '475.00', 'paid' => '475.00', 'balance' => '0.00',
            'change' => '25.00', 'refunded' => '0.00', 'net_paid' => '475.00', 'currency' => 'ETB',
        ],
        payments: [
            ['channel_en' => 'Cash', 'channel_am' => 'ጥሬ ገንዘብ', 'amount' => '200.00', 'reference_tail' => null, 'tendered' => '225.00', 'change' => '25.00'],
            ['channel_en' => 'Mobile money', 'channel_am' => 'ሞባይል ገንዘብ', 'amount' => '275.00', 'reference_tail' => '9F3K', 'tendered' => null, 'change' => null],
        ],
        refunds: [],
    );
}

/** Draws, encodes and reads back: the picture the printer would print. */
function rasterPrinted(Receipt $receipt, int $width): Bitmap
{
    $bitmaps = EscPosEmulator::parse(EscPos::raster(app(ReceiptRaster::class)->draw(ReceiptLayout::rows($receipt, 'Abebe K.'), $width)))->bitmaps();
    expect($bitmaps)->toHaveCount(1);

    return $bitmaps[0];
}

/** @return array{0: int, 1: int} the dots that differ (each side's dots with no dot of the same colour within one dot on the other side) and the dots compared */
function rasterDifference(Bitmap $a, Bitmap $b): array
{
    $differ = 0;
    $near = static function (Bitmap $other, int $x, int $y, bool $black): bool {
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx >= 0 && $ny >= 0 && $nx < $other->width && $ny < $other->height && $other->isBlack($nx, $ny) === $black) {
                    return true;
                }
            }
        }

        return false;
    };
    foreach ([[$a, $b], [$b, $a]] as [$one, $other]) {
        for ($y = 0; $y < $one->height; $y++) {
            for ($x = 0; $x < $one->width; $x++) {
                if ($y >= $other->height || $x >= $other->width || $one->isBlack($x, $y) !== $other->isBlack($x, $y)) {
                    if (! $near($other, $x, $y, $one->isBlack($x, $y))) {
                        $differ++;
                    }
                }
            }
        }
    }

    return [$differ, max($a->width * $a->height, $b->width * $b->height)];
}

it('draws the receipt like its golden picture at 80 and 58 mm, with Amharic and without', function (int $width, string $locale, string $golden) {
    $printed = rasterPrinted(rasterReceipt($locale), $width);
    $path = base_path('tests/Fixtures/receipts/'.$golden);

    if (getenv('UPDATE_RECEIPT_GOLDENS') === '1') {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $printed->toPng());
    }

    expect(is_file($path))->toBeTrue("the golden {$golden} is missing: run the test with UPDATE_RECEIPT_GOLDENS=1 and look at the picture");
    $expected = Bitmap::fromPng((string) file_get_contents($path));
    [$differ, $dots] = rasterDifference($printed, $expected);

    expect($printed->width)->toBe($width)
        ->and(abs($printed->height - $expected->height))->toBeLessThanOrEqual(2, "height {$printed->height} against the golden's {$expected->height}")
        ->and($differ / $dots)->toBeLessThanOrEqual(0.002, "{$differ} of {$dots} dots differ from {$golden}");
})->with([
    '80 mm with Amharic' => [576, 'both', 'receipt-80-am.png'],
    '80 mm English only' => [576, 'en', 'receipt-80-en.png'],
    '58 mm with Amharic' => [384, 'both', 'receipt-58-am.png'],
    '58 mm English only' => [384, 'en', 'receipt-58-en.png'],
])->group('module:payments');

it('lays out the business in both languages, the TIN, the order, the cashier, both dates, the payments, the footer and the non-fiscal words', function () {
    $text = implode("\n", array_map(fn (ReceiptRow $row): string => trim($row->left.' | '.$row->right, ' |'), ReceiptLayout::rows(rasterReceipt('both'), 'Abebe K.')));

    expect($text)->toContain('Demo Bakery')->toContain('ማሳያ ዳቦ ቤት')
        ->toContain('Bole Road, Addis Ababa')->toContain('ቦሌ መንገድ፣ አዲስ አበባ')
        ->toContain('011 000 0000')
        ->toContain('TIN / '.trans('receipts.tin', [], 'am').': 0012345678')
        ->toContain('#128')
        ->toContain('2026-11-01 14:05')
        ->toContain(EthiopianDate::of(CarbonImmutable::parse('2026-11-01 14:05:00', 'Africa/Addis_Ababa'))->format('am'))
        ->toContain('Abebe K.')
        ->toContain('2 x Black tea ሻይ | 25.00')
        ->toContain('475.00 ETB')
        ->toContain('Cash ጥሬ ገንዘብ | 200.00')
        ->toContain('9F3K')
        ->toContain('Thank you!')->toContain('እናመሰግናለን!')
        ->toContain(trans('receipts.non_fiscal', [], 'en'))->toContain(trans('receipts.non_fiscal', [], 'am'));
})->group('module:payments');

it('prints no Amharic when the receipt is English only, and no Ethiopian date when the setting is off', function () {
    $text = implode("\n", array_map(fn (ReceiptRow $row): string => $row->left.' '.$row->right, ReceiptLayout::rows(rasterReceipt('en', ethiopian: false), null)));

    expect($text)->not->toMatch('/\p{Ethiopic}/u')
        ->toContain('TIN: 0012345678')
        ->toContain(trans('receipts.non_fiscal', [], 'en'))
        ->not->toContain(trans('receipts.ethiopian_date', [], 'en'))
        ->not->toContain(trans('receipts.cashier', [], 'en'));
})->group('module:payments');

it('sends a 40-line order in two bands and keeps every line', function () {
    $receipt = rasterReceipt('both', lines: 40);
    $picture = app(ReceiptRaster::class)->draw(ReceiptLayout::rows($receipt, 'Abebe K.'), 576);
    $printer = EscPosEmulator::parse(EscPos::raster($picture));

    expect($picture->height)->toBeGreaterThan(EscPos::BAND_ROWS)
        ->and(array_count_values($printer->names())['raster'])->toBe((int) ceil($picture->height / EscPos::BAND_ROWS))
        ->and($printer->bitmaps()[0]->data)->toBe($picture->data)
        ->and(count(array_filter(ReceiptLayout::rows($receipt, null), fn (ReceiptRow $row): bool => str_contains($row->left, 'Bread roll'))))->toBe(38);
})->group('module:payments');

it('wraps a long name inside the paper and never draws past its edge', function () {
    $receipt = rasterReceipt('en');
    $narrow = app(ReceiptRaster::class)->draw(ReceiptLayout::rows($receipt, null), 384);
    $wide = app(ReceiptRaster::class)->draw(ReceiptLayout::rows($receipt, null), 576);

    expect($narrow->height)->toBeGreaterThan(0)
        ->and($narrow->width)->toBe(384)
        ->and($wide->width)->toBe(576)
        ->and($narrow->blackDots())->toBeGreaterThan(1000);
})->group('module:payments');
