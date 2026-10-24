<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/*
 * Step 4.2a: `php artisan files:doctor` prints one ok / missing / warn row per host requirement and ends with a real round trip: a
 * 3000x2000 JPEG with a planted GPS block goes through ImageProcessor and is read back.
 */

it('prints the host checks and passes the round trip', function () {
    Storage::fake(config('files.disk'));

    $code = Artisan::call('files:doctor');
    $out = Artisan::output();

    expect($code)->toBe(0, $out);
    foreach (['PHP', 'gd', 'JPEG Support', 'PNG Support', 'WebP Support', 'FreeType Support', 'exif', 'fileinfo', 'mbstring', 'zip', 'dom', 'xmlwriter',
        'xmlreader', 'libxml', 'intl', 'memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'free space', 'writable', 'round trip'] as $row) {
        expect($out)->toContain($row);
    }
    expect($out)->toContain('exif stripped: yes')->toContain('longest edge: 1600')->toMatch('/\bok\b/');
})->group('module:shared', 'feature');

it('leaves nothing behind in the files folder', function () {
    $disk = Storage::fake(config('files.disk'));

    Artisan::call('files:doctor');

    expect($disk->allFiles())->toBe([]);
})->group('module:shared', 'feature');

it('says what the rows are as data for the web check of step 4.2b', function () {
    Storage::fake(config('files.disk'));

    $rows = (new App\Shared\Files\Doctor)->rows();

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect($row)->toHaveKeys(['check', 'status', 'detail'])->and($row['status'])->toBeIn(['ok', 'missing', 'warn']);
    }
    $byCheck = array_column($rows, 'status', 'check');
    expect($byCheck['gd'])->toBe('ok')->and($byCheck['round trip'])->toBe('ok');
})->group('module:shared', 'feature');
