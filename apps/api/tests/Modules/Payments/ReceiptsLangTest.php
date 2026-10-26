<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * Rule 9 for what a receipt prints (step 4.3e): every label of lang/{en,am}/receipts.php is in both files, every Amharic one is written
 * in Ethiopic script, and the order types, the export headers and the pro-forma warning are all there.
 */

it('has every receipt and export label in English and in Amharic', function () {
    $en = Arr::dot(require base_path('lang/en/receipts.php'));
    $am = Arr::dot(require base_path('lang/am/receipts.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach ($en as $key => $text) {
        expect($text)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key])->toBeString()->toMatch('/\p{Ethiopic}/u', "am {$key}");
    }
    foreach (['title', 'proforma', 'proforma_note', 'total', 'change', 'refunds', 'net_paid', 'more_items', 'type.dine_in', 'type.takeaway',
        'type.delivery', 'type.advance', 'export.amount', 'export.has_photo', 'export.yes', 'export.no'] as $key) {
        expect($en)->toHaveKey($key);
    }
})->group('module:payments');
