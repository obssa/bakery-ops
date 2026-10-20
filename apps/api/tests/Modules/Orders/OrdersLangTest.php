<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * Rule 9 for the Orders messages: every message_key Orders answers with (and the shortfall line it builds) has an
 * English and an Amharic text in lang/{en,am}/orders.php, the two files carry the same keys, and no Amharic text is a
 * copy of the English one. The keys of step 1.3b are listed: a key the code uses without a text would answer the key
 * itself to the waiter.
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
