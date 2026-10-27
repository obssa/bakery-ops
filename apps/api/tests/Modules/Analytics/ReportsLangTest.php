<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * Rule 9 for what the analytics files print (step 4.4d): the sheet names, the column headers, the values (yes and no, the kinds of item and
 * of work), the About sheet, the PDF's titles and stamps and the weekly summary's PDF are lang/{en,am}/reports.php. The two files carry the
 * same keys and the same placeholders in each pair, every Amharic text is Amharic and not a copy of the English, and a sheet name is a name a
 * spreadsheet accepts (at most 31 characters, none of : \ / ? * [ ]).
 */

pest()->group('module:analytics');

it('has the same keys and placeholders in English and Amharic, and no Amharic copy of the English', function () {
    $en = Arr::dot(require base_path('lang/en/reports.php'));
    $am = Arr::dot(require base_path('lang/am/reports.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach ($en as $key => $english) {
        preg_match_all('/:([a-z_]+)/', (string) $english, $inEnglish);
        preg_match_all('/:([a-z_]+)/', (string) $am[$key], $inAmharic);
        expect($am[$key])->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($english, "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u', "am {$key} is Amharic")
            ->and(array_unique($inAmharic[1]))->toEqualCanonicalizing(array_unique($inEnglish[1]), "{$key}: the same placeholders in both languages");
    }
});

it('names every sheet as a spreadsheet allows, a report title for each report and a header for every column the exports write', function () {
    foreach (['en', 'am'] as $locale) {
        $lang = require base_path("lang/{$locale}/reports.php");
        foreach ($lang['sheets'] as $key => $name) {
            expect(mb_strlen($name))->toBeLessThanOrEqual(31, "{$locale} sheet {$key}")
                ->and($name)->not->toMatch('~[:\\\\/?*\[\]]~', "{$locale} sheet {$key}");
        }
        expect(array_keys($lang['titles']))->toEqualCanonicalizing(['sales', 'channels', 'items', 'stations', 'staff', 'shifts', 'weekly']);
    }
});
