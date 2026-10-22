<?php

declare(strict_types=1);

use App\Shared\Http\ApiError;
use Illuminate\Support\Arr;

require_once __DIR__.'/../../Support/shifts_messages.php';

pest()->group('module:shifts', 'invariants');

/*
 * Rule 9 for the Shifts messages of step 3.3a: every message_key the close, count and variance-reason endpoints answer with has an
 * English and an Amharic text in lang/{en,am}/shifts.php, the two files carry the same keys, and no Amharic text is a copy of the
 * English one. The keys of step 3.2b are in ShiftsLangTest; between the two tests no key lacks a test and no test lacks a key.
 */

it('has an English and an Amharic text for every count message, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/shifts.php'));
    $am = Arr::dot(require base_path('lang/am/shifts.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach (countsMessageKeys() as $full) {
        $key = substr($full, strlen('shifts.'));
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
});

it('answers every count error key with both languages through ApiError', function () {
    foreach (countsMessageKeys() as $key) {
        $body = ApiError::body($key);
        expect($body['message_key'])->toBe($key)
            ->and($body['message_en'])->not->toBe($key)->not->toBe("shared.{$key}")
            ->and($body['message_am'])->not->toBe($body['message_en'])->not->toBe($key)
            ->and($body['message_am'])->toMatch('/\p{Ethiopic}/u');
    }
});
