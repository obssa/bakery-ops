<?php

declare(strict_types=1);

use App\Shared\Http\ApiError;
use Illuminate\Support\Arr;

/*
 * Rule 9 for the reorder-rule messages (step 2.6a): every message_key the reorder rule endpoints answer with has an English and an
 * Amharic text in lang/{en,am}/inventory.php (the dictionary of App\Modules\Inventory), the two files carry the same
 * keys, and no Amharic text is a copy of the English one.
 */

const REORDER_MESSAGE_KEYS = [
    'inventory.reorder.exists', 'inventory.reorder.role_unknown', 'inventory.reorder.item_unknown', 'inventory.reorder.location_unknown',
];

it('has an English and an Amharic text for every reorder-rule message, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/inventory.php'));
    $am = Arr::dot(require base_path('lang/am/inventory.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach (REORDER_MESSAGE_KEYS as $full) {
        $key = substr($full, strlen('inventory.'));
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
})->group('module:inventory', 'invariants');

it('answers every reorder rule error key with both languages through ApiError', function () {
    foreach (REORDER_MESSAGE_KEYS as $key) {
        $body = ApiError::body($key);
        expect($body['message_key'])->toBe($key)
            ->and($body['message_en'])->not->toBe($key)->not->toBe("shared.{$key}")
            ->and($body['message_am'])->not->toBe($body['message_en'])->not->toBe($key)
            ->and($body['message_am'])->toMatch('/\p{Ethiopic}/u');
    }
})->group('module:inventory', 'invariants');
