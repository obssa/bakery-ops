<?php

declare(strict_types=1);

use App\Shared\Http\ApiError;
use Illuminate\Support\Arr;

/*
 * Rule 9 for the purchase messages (step 2.2b): every message_key the purchase endpoints answer with has an English and an
 * Amharic text in lang/{en,am}/purchases.php, the two files carry the same keys, and no Amharic text is a copy of the
 * English one. inventory.cost.exists and inventory.cost.not_found live in inventory.php (InventorySchemaTest keeps that
 * pair in step) and are checked here too, since the cost endpoints answer with them.
 */

it('has an English and an Amharic text for every purchase message, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/purchases.php'));
    $am = Arr::dot(require base_path('lang/am/purchases.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    $keys = [
        'supplier.unknown', 'location.unknown', 'item.not_purchasable', 'unit.incompatible', 'line.cost_too_small',
        'invoice.duplicate', 'purchase.not_draft', 'purchase.cancelled', 'purchase.confirmed', 'purchase.empty',
        'cost.currency_mismatch', 'validation.too_large',
    ];
    foreach ($keys as $key) {
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
    expect(array_keys($en))->toHaveCount(count($keys));
})->group('module:inventory', 'invariants');

it('answers every purchase and cost error key with both languages through ApiError', function () {
    foreach ([
        'purchases.supplier.unknown', 'purchases.location.unknown', 'purchases.item.not_purchasable', 'purchases.unit.incompatible',
        'purchases.line.cost_too_small', 'purchases.invoice.duplicate', 'purchases.purchase.not_draft', 'purchases.purchase.cancelled',
        'purchases.purchase.confirmed', 'purchases.purchase.empty', 'purchases.cost.currency_mismatch',
        'inventory.cost.exists', 'inventory.cost.not_found',
    ] as $key) {
        $body = ApiError::body($key);
        expect($body['message_key'])->toBe($key)
            ->and($body['message_en'])->not->toBe($key)->not->toBe("shared.{$key}")
            ->and($body['message_am'])->not->toBe($body['message_en'])->not->toBe($key)
            ->and($body['message_am'])->toMatch('/\p{Ethiopic}/u');
    }
})->group('module:inventory', 'invariants');
