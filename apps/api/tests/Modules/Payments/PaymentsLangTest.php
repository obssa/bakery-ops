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
        // step 4.3a: channel administration
        'channel.code_taken', 'channel.pattern_invalid', 'channel.cash_locked', 'channel.order_incomplete',
        // step 4.3b: photos, reference patterns, split bills and the duplicate warnings
        'photo.invalid', 'reference.invalid', 'batch.sum_mismatch', 'batch.row_invalid', 'warning.reference_duplicate', 'warning.receipt_duplicate',
        // step 4.3c: dispute and void
        'dispute.reason_required', 'void.order_closed', 'void.refunded', 'void.reason_required', 'void.pin_required',
        // step 4.3e: receipts, reconciliation and the export
        'receipt.unpaid', 'receipt.link_invalid', 'reconciliation.range_too_large', 'export.too_large',
    ] as $key) {
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
    expect(array_keys($en))->toHaveCount(29);
})->group('module:payments');

it('no longer tells the till that photos cannot be taken, that part payments are refused, or that only cash needs a shift (step 4.3b)', function () {
    $en = Arr::dot(require base_path('lang/en/payments.php'));

    expect($en['channel.photo_required'])->not->toContain('cannot be taken yet')
        ->and($en['amount.mismatch'])->toContain('more than the balance')
        ->and($en['shift.required'])->not->toContain('before taking cash');
})->group('module:payments');
