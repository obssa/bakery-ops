<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * Rule 9 for what Audit says (step 4.5a): the refusal of an export that is too large, the note that stands in an xlsx cell for a JSON too
 * long for it, and the export's column headers, in lang/{en,am}/audit.php with the same keys in both files, and no Amharic text a copy of
 * the English one.
 */

it('has an English and an Amharic text for every Audit message and header, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/audit.php'));
    $am = Arr::dot(require base_path('lang/am/audit.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    $columns = ['id', 'at', 'actor_id', 'actor', 'acting_user_id', 'acting_user', 'action', 'subject_type', 'subject_id', 'station_id', 'shift_id',
        'device_id', 'ip', 'changed', 'reason', 'before', 'after', 'context'];
    $keys = array_merge(['export.too_large', 'export.too_long'], array_map(fn (string $c): string => "export.columns.{$c}", $columns));
    expect(array_keys($en))->toEqualCanonicalizing($keys);
    foreach ($keys as $key) {
        expect($en[$key])->toBeString()->not->toBeEmpty("en {$key}")
            ->and($am[$key])->toBeString()->not->toBeEmpty("am {$key}")
            ->and($am[$key])->not->toBe($en[$key], "am {$key} is not a copy of the English")
            ->and($am[$key])->toMatch('/\p{Ethiopic}/u');
    }
    expect($en['export.too_long'])->toContain(':id')->and($am['export.too_long'])->toContain(':id');
})->group('module:audit');
