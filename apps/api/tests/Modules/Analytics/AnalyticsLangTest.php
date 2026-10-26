<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * Rule 9 for the Analytics messages (step 4.4b on): every message_key Analytics answers with has an English and an Amharic text in
 * lang/{en,am}/analytics.php, the two files carry the same keys, and no Amharic text is a copy of the English one. Step 4.4d adds the
 * refusals of the exports and of the weekly summary; the labels of the files are lang/{en,am}/reports.php (ReportsLangTest).
 */

it('has an English and an Amharic text for every Analytics message, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/analytics.php'));
    $am = Arr::dot(require base_path('lang/am/analytics.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach (['range.too_large', 'range.future', 'rebuild.running', 'export.too_large', 'weekly.week_open', 'weekly.link_invalid'] as $key) {
        expect($en[$key] ?? null)->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key] ?? null)->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
    expect(array_keys($en))->toHaveCount(6);
})->group('module:analytics');
