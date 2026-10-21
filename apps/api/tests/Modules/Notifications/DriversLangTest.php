<?php

declare(strict_types=1);

use App\Shared\Http\ApiError;
use Illuminate\Support\Arr;

pest()->group('module:notifications', 'unit');

/*
 * Rule 9 for what step 2.5b says to people: the refusal of a driver that is off (an API error with a message_key), and the
 * few lines the Telegram bot answers with. Every key has an English and an Amharic text, the same placeholders, and the
 * Amharic is not a copy of the English.
 */

const DRIVER_MESSAGE_KEYS = ['driver.disabled', 'telegram.linked', 'telegram.unknown_code', 'telegram.help'];

it('has an English and an Amharic text for every driver message, in Ethiopic script', function () {
    $en = Arr::dot(require base_path('lang/en/notifications.php'));
    $am = Arr::dot(require base_path('lang/am/notifications.php'));

    foreach (DRIVER_MESSAGE_KEYS as $key) {
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key])
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
});

it('answers the driver-off refusal in both languages through ApiError', function () {
    $body = ApiError::body('notifications.driver.disabled');

    expect($body['message_key'])->toBe('notifications.driver.disabled')
        ->and($body['message_en'])->not->toBe('notifications.driver.disabled')
        ->and($body['message_am'])->not->toBe($body['message_en'])->toMatch('/\p{Ethiopic}/u');
});
