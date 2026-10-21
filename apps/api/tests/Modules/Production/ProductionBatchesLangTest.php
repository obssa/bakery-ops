<?php

declare(strict_types=1);

use App\Shared\Http\ApiError;
use Illuminate\Support\Arr;

pest()->group('module:production', 'invariants');

/*
 * Rule 9 for the batch messages (step 2.7c): every message_key the batch endpoints answer with has an English and an Amharic
 * text in lang/{en,am}/production.php, the two files keep the same keys, and no Amharic text is a copy of the English one.
 * The shortfall message takes the ingredient, the quantity short, its unit and the place (:ingredient :short :unit :place) and
 * says in a second sentence when more than one ingredient is short (:more); both languages use the same placeholders.
 */

const PRODUCTION_BATCH_MESSAGE_KEYS = [
    'production.request.not_accepted', 'production.locations.unresolved', 'production.batch.ingredient_short',
    'production.batch.self_confirm', 'production.confirm.forbidden',
];

it('has an English and an Amharic text for every batch message, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/production.php'));
    $am = Arr::dot(require base_path('lang/am/production.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach (PRODUCTION_BATCH_MESSAGE_KEYS as $full) {
        $key = substr($full, strlen('production.'));
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
});

it('answers every batch error key with both languages through ApiError', function () {
    foreach (PRODUCTION_BATCH_MESSAGE_KEYS as $key) {
        $body = ApiError::body($key);
        expect($body['message_key'])->toBe($key)
            ->and($body['message_en'])->not->toBe($key)->not->toBe("shared.{$key}")
            ->and($body['message_am'])->not->toBe($body['message_en'])->not->toBe($key)
            ->and($body['message_am'])->toMatch('/\p{Ethiopic}/u');
    }
});

it('uses the same placeholders in both languages for the shortfall message', function () {
    $en = require base_path('lang/en/production.php');
    $am = require base_path('lang/am/production.php');
    $placeholders = fn (string $text): array => collect(preg_match_all('/:([a-z_]+)/', $text, $m) ? $m[1] : [])->unique()->sort()->values()->all();

    expect($placeholders($en['batch']['ingredient_short']))->toBe(['ingredient', 'more', 'place', 'short', 'unit'])
        ->and($placeholders($am['batch']['ingredient_short']))->toBe(['ingredient', 'more', 'place', 'short', 'unit']);
});
