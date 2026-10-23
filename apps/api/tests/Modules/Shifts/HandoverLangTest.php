<?php

declare(strict_types=1);

use App\Shared\Http\ApiError;
use Illuminate\Support\Arr;

require_once __DIR__.'/../../Support/shifts_messages.php';

pest()->group('module:shifts', 'invariants');

/*
 * Rule 9 for the Shifts messages of step 3.6: the replay's error key has an English and an Amharic text in lang/{en,am}/shifts.php, the two
 * files carry the same keys, and the Amharic is not a copy of the English. (The notes and the handover panel reuse keys of earlier steps.)
 */

it('has an English and an Amharic text for every handover message, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/shifts.php'));
    $am = Arr::dot(require base_path('lang/am/shifts.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach (handoverMessageKeys() as $full) {
        $key = substr($full, strlen('shifts.'));
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key])
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
});

it('answers every handover error key with both languages through ApiError', function () {
    foreach (handoverMessageKeys() as $key) {
        $body = ApiError::body($key);
        expect($body['message_key'])->toBe($key)
            ->and($body['message_en'])->not->toBe($key)
            ->and($body['message_am'])->not->toBe($body['message_en'])->toMatch('/\p{Ethiopic}/u');
    }
});
