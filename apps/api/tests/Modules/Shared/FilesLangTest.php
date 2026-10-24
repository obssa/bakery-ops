<?php

declare(strict_types=1);

use App\Shared\Files\FilesError;
use Illuminate\Support\Arr;

/*
 * Rule 9 for the Files messages: every message_key FilesError carries has an English and an Amharic text in lang/{en,am}/files.php.
 */

it('has an English and an Amharic text for every Files refusal, with the same keys in both files', function () {
    $en = Arr::dot(require base_path('lang/en/files.php'));
    $am = Arr::dot(require base_path('lang/am/files.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en));
    foreach (FilesError::KEYS as $key) {
        $short = substr($key, strlen('files.'));
        expect($en[$short] ?? null)->toBeString()->not->toBeEmpty("en {$short}")
            ->and($am[$short] ?? null)->toBeString()->not->toBeEmpty("am {$short}")
            ->and($am[$short])->not->toBe($en[$short])
            ->and($am[$short])->toMatch('/\p{Ethiopic}/u');
    }
    expect(array_keys($en))->toHaveCount(count(FilesError::KEYS));
})->group('module:shared', 'feature');
