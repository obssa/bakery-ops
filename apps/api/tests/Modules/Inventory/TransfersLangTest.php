<?php

declare(strict_types=1);

use App\Shared\Http\ApiError;
use Illuminate\Support\Arr;

/*
 * Rule 9 for the transfer messages (step 2.3): every message_key the transfer endpoints answer with has an English and an
 * Amharic text in lang/{en,am}/inventory.php (the dictionary of App\Modules\Inventory), the two files carry the same
 * keys, and no Amharic text is a copy of the English one.
 */

const TRANSFER_MESSAGE_KEYS = [
    'inventory.transfer.same_location', 'inventory.transfer.item_not_stockable', 'inventory.transfer.location_unknown',
    'inventory.transfer.destination_forbidden', 'inventory.transfer.source_forbidden', 'inventory.transfer.insufficient',
    'inventory.transfer.confirmed', 'inventory.transfer.rejected', 'inventory.transfer.qty_too_large', 'inventory.transfer.qty_too_small',
];

it('has an English and an Amharic text for every transfer message, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/inventory.php'));
    $am = Arr::dot(require base_path('lang/am/inventory.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach (TRANSFER_MESSAGE_KEYS as $full) {
        $key = substr($full, strlen('inventory.'));
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
})->group('module:inventory', 'invariants');

it('answers every transfer error key with both languages through ApiError', function () {
    foreach (TRANSFER_MESSAGE_KEYS as $key) {
        $body = ApiError::body($key);
        expect($body['message_key'])->toBe($key)
            ->and($body['message_en'])->not->toBe($key)->not->toBe("shared.{$key}")
            ->and($body['message_am'])->not->toBe($body['message_en'])->not->toBe($key)
            ->and($body['message_am'])->toMatch('/\p{Ethiopic}/u');
    }
})->group('module:inventory', 'invariants');
