<?php

declare(strict_types=1);

use App\Shared\Http\ApiError;
use Illuminate\Support\Arr;

pest()->group('module:production', 'invariants');

/*
 * Rule 9 for the production request messages (step 2.7b): every message_key the request endpoints answer with has an English
 * and an Amharic text in lang/{en,am}/production.php (the dictionary of App\Modules\Production), the two files carry the same
 * keys, and no Amharic text is a copy of the English one.
 */

const PRODUCTION_REQUEST_MESSAGE_KEYS = [
    'production.plan.required', 'production.station.forbidden', 'production.cancel.forbidden',
    'production.request.already_open', 'production.request.taken', 'production.request.in_progress',
    'production.product.not_producible', 'production.product.no_station', 'production.assignee.invalid',
];

it('has an English and an Amharic text for every production request message, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/production.php'));
    $am = Arr::dot(require base_path('lang/am/production.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach (PRODUCTION_REQUEST_MESSAGE_KEYS as $full) {
        $key = substr($full, strlen('production.'));
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
});

it('answers every production request error key with both languages through ApiError', function () {
    foreach (PRODUCTION_REQUEST_MESSAGE_KEYS as $key) {
        $body = ApiError::body($key);
        expect($body['message_key'])->toBe($key)
            ->and($body['message_en'])->not->toBe($key)->not->toBe("shared.{$key}")
            ->and($body['message_am'])->not->toBe($body['message_en'])->not->toBe($key)
            ->and($body['message_am'])->toMatch('/\p{Ethiopic}/u');
    }
});
