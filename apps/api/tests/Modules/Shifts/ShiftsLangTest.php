<?php

declare(strict_types=1);

use App\Shared\Http\ApiError;
use Illuminate\Support\Arr;

require_once __DIR__.'/../../Support/shifts_messages.php';

pest()->group('module:shifts', 'invariants');

/*
 * Rule 9 for the Shifts messages of step 3.2b: every message_key the shift, template and roster endpoints answer with has an English and
 * an Amharic text in lang/{en,am}/shifts.php (the dictionary of App\Modules\Shifts), the two files carry the same keys, and no
 * Amharic text is a copy of the English one.
 */

const SHIFTS_MESSAGE_KEYS = [
    'shifts.station.forbidden', 'shifts.station.not_tracked',
    'shifts.shift.already_open', 'shifts.shift.already_scheduled', 'shifts.shift.not_scheduled', 'shifts.shift.outside_window',
    'shifts.shift.not_running', 'shifts.shift.not_cancellable',
    'shifts.start.force_forbidden', 'shifts.cancel.forbidden',
    'shifts.template.window_invalid', 'shifts.template.station_unknown', 'shifts.template.not_for_station',
    'shifts.roster.user_invalid',
];

it('has an English and an Amharic text for every shifts message, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/shifts.php'));
    $am = Arr::dot(require base_path('lang/am/shifts.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach (SHIFTS_MESSAGE_KEYS as $full) {
        $key = substr($full, strlen('shifts.'));
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
    // the keys of step 3.3a (CountsLangTest) are tested there: between the two lists no key lacks a test and no test lacks a key
    $tested = array_map(fn (string $k): string => substr($k, strlen('shifts.')), array_merge(SHIFTS_MESSAGE_KEYS, countsMessageKeys()));
    expect(array_keys($en))->toEqualCanonicalizing($tested, 'no key without a test, no test without a key');
});

it('answers every shifts error key with both languages through ApiError', function () {
    foreach (SHIFTS_MESSAGE_KEYS as $key) {
        $body = ApiError::body($key);
        expect($body['message_key'])->toBe($key)
            ->and($body['message_en'])->not->toBe($key)->not->toBe("shared.{$key}")
            ->and($body['message_am'])->not->toBe($body['message_en'])->not->toBe($key)
            ->and($body['message_am'])->toMatch('/\p{Ethiopic}/u');
    }
});
