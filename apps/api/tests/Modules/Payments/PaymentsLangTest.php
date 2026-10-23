<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * Rule 9 for the Payments messages: every message_key Payments answers with has an English and an Amharic text in
 * lang/{en,am}/payments.php, the two files carry the same keys, and no Amharic text is a copy of the English one.
 */

it('has an English and an Amharic text for every Payments message, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/payments.php'));
    $am = Arr::dot(require base_path('lang/am/payments.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach ([
        'channel.unknown', 'channel.disabled', 'channel.photo_required', 'channel.reference_required',
        'amount.mismatch', 'tendered.too_low',
        'order.not_payable', 'order.settled', 'client_uuid.reused', 'shift.required',
    ] as $key) {
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
    expect(array_keys($en))->toHaveCount(10);
})->group('module:payments');
