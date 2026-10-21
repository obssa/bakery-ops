<?php

declare(strict_types=1);

use App\Modules\Notifications\Support\NotificationTypes;
use Illuminate\Support\Arr;

/*
 * Rule 9 for the Notifications texts: lang/en/notifications.php and lang/am/notifications.php carry the same keys, each
 * Amharic text asks for the same placeholders as its English twin (so NotificationText fills both from one payload), and no
 * Amharic text is a copy of the English. Every type of the registry has a title and a body.
 */

it('has the same keys in English and Amharic, the same placeholders in each pair, and no Amharic copy of the English', function () {
    $en = Arr::dot(require base_path('lang/en/notifications.php'));
    $am = Arr::dot(require base_path('lang/am/notifications.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach ($en as $key => $english) {
        preg_match_all('/:([a-z_]+)/', (string) $english, $inEnglish);
        preg_match_all('/:([a-z_]+)/', (string) $am[$key], $inAmharic);
        expect($am[$key])->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($english, "am {$key} is not a copy of the English")
            ->and(array_unique($inAmharic[1]))->toEqualCanonicalizing(array_unique($inEnglish[1]), "{$key}: the same placeholders in both languages");
    }
})->group('module:notifications', 'unit');

it('gives every type of the registry a title and a body in both languages', function () {
    $en = Arr::dot(require base_path('lang/en/notifications.php'));

    foreach (NotificationTypes::all() as $type) {
        $keys = array_filter(array_keys($en), fn (string $key): bool => str_starts_with($key, "text.{$type}."));
        expect(array_values(array_filter($keys, fn (string $k): bool => str_ends_with($k, '.title'))))->not->toBe([], "{$type} has a title")
            ->and(array_values(array_filter($keys, fn (string $k): bool => str_ends_with($k, '.body') || str_contains($k, '.body_'))))->not->toBe([], "{$type} has a body");
    }
})->group('module:notifications', 'unit');
