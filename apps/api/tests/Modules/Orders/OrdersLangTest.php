<?php

declare(strict_types=1);

use App\Modules\Orders\Enums\RejectReason;
use Illuminate\Support\Arr;

/*
 * Rule 9 for the Orders messages: every message_key Orders answers with (and the shortfall line it builds) has an
 * English and an Amharic text in lang/{en,am}/orders.php, the two files carry the same keys, and no Amharic text is a
 * copy of the English one. The keys of steps 1.3b and 1.3c are listed: a key the code uses without a text would answer
 * the key itself to the waiter. Every reject reason of the fixed list (RejectReason) has its text in both languages:
 * the pad shows it on the line the station gave back.
 */

it('has an English and an Amharic text for every Orders message, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/orders.php'));
    $am = Arr::dot(require base_path('lang/am/orders.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach ([
        'send.shortfall', 'send.shortfall_line', 'send.shortfall_line_no_station',
        'order.not_open', 'order.not_owner', 'order.empty',
        'item.not_sellable', 'item.no_price', 'item.no_recipe', 'item.no_location',
        'modifier.not_of_item', 'table.unknown', 'line.changed',
        'order.not_sent', 'station.forbidden',
        'order.paid', 'order.not_voidable', 'void.reason_required', 'void.pin_required',
        'table.code_taken',
        // step 4.1b: the refusals of Orders::refund
        'refund.reason_required', 'refund.not_refundable', 'refund.line_invalid', 'refund.line_not_refundable', 'refund.line_refunded',
        'refund.window_closed', 'refund.partial_not_allowed', 'refund.exceeds_paid', 'refund.client_uuid_reused', 'refund.approver_forbidden',
    ] as $key) {
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English");
    }
    foreach (['shortfall_line', 'shortfall_line_no_station'] as $line) {
        foreach ([':item', ':qty', ':ingredient', ':short'] as $placeholder) {
            expect($en["send.{$line}"])->toContain($placeholder)->and($am["send.{$line}"])->toContain($placeholder);
        }
    }
    expect($en['send.shortfall_line'])->toContain(':station')->and($am['send.shortfall_line'])->toContain(':station');
})->group('module:orders');

it('has the text of every reject reason in English and Amharic, and nothing else under reject.reason', function () {
    $en = Arr::dot(require base_path('lang/en/orders.php'));
    $am = Arr::dot(require base_path('lang/am/orders.php'));
    $listed = fn (array $dict): array => array_values(array_map(
        fn (string $key): string => substr($key, strlen('reject.reason.')),
        array_filter(array_keys($dict), fn (string $key): bool => str_starts_with($key, 'reject.reason.')),
    ));

    expect($listed($en))->toEqualCanonicalizing(RejectReason::values())
        ->and($listed($am))->toEqualCanonicalizing(RejectReason::values());
    foreach (RejectReason::cases() as $reason) {
        expect($reason->messageKey())->toBe("orders.reject.reason.{$reason->value}")
            ->and($reason->message('en'))->toBe($en["reject.reason.{$reason->value}"])
            ->and($reason->message('am'))->toBe($am["reject.reason.{$reason->value}"])
            ->and($reason->message('am'))->not->toBe($reason->message('en'));
    }
    expect(RejectReason::IngredientSpoiled->message('en'))->toBe('An ingredient has spoiled.')
        ->and(RejectReason::Other->needsNote())->toBeTrue()
        ->and(RejectReason::OutOfStock->needsNote())->toBeFalse();
})->group('module:orders');
