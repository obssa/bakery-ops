<?php

declare(strict_types=1);

use App\Shared\Http\ApiError;
use Illuminate\Support\Arr;

/*
 * Rule 9 for the stock views (step 2.6b): the one message_key the stock strip answers with has an English and an Amharic text in
 * lang/{en,am}/inventory.php, the two files carry the same keys, and the Amharic is not a copy of the English.
 */

const STOCK_VIEW_MESSAGE_KEYS = ['inventory.station.forbidden'];

it('has an English and an Amharic text for every stock view message, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/inventory.php'));
    $am = Arr::dot(require base_path('lang/am/inventory.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach (STOCK_VIEW_MESSAGE_KEYS as $full) {
        $key = substr($full, strlen('inventory.'));
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
})->group('module:inventory', 'invariants');

it('answers every stock view error key with both languages through ApiError', function () {
    foreach (STOCK_VIEW_MESSAGE_KEYS as $key) {
        $body = ApiError::body($key);
        expect($body['message_key'])->toBe($key)
            ->and($body['message_en'])->not->toBe($key)->not->toBe("shared.{$key}")
            ->and($body['message_am'])->not->toBe($body['message_en'])->not->toBe($key)
            ->and($body['message_am'])->toMatch('/\p{Ethiopic}/u');
    }
})->group('module:inventory', 'invariants');
