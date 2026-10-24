<?php

declare(strict_types=1);

use App\Shared\Files\Files;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Step 4.2a: nothing Files writes can be fetched by a plain URL. Both disks are private and outside public/, no storage:link target
 * points at them, and putting a file creates nothing under public/.
 */

/** @return list<string> */
function publicTree(): array
{
    $paths = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(public_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        $paths[] = $file->getPathname();
    }
    sort($paths);

    return $paths;
}

it('keeps both disks private and outside public/', function (string $disk) {
    $config = config("filesystems.disks.{$disk}");

    expect($config['visibility'])->toBe('private')
        ->and($config['throw'])->toBeTrue()
        ->and($config)->not->toHaveKey('url');
    if ($config['driver'] === 'local') {
        expect($config['root'])->toBe(storage_path('app/private/files'))
            ->and($config['root'])->not->toStartWith(public_path())
            ->and($config['serve'] ?? false)->toBeFalse();
    }
})->with(['files', 'files_s3'])->group('module:shared', 'feature');

it('does not link any disk of Files into public/', function () {
    foreach (config('filesystems.links') as $link => $target) {
        expect($target)->not->toContain('files')->and($link)->not->toContain('files');
    }
    expect(config('filesystems.disks.files_s3.driver'))->toBe('s3');
})->group('module:shared', 'feature');

it('creates nothing under public/ when a file is put', function () {
    Storage::fake(config('files.disk'));
    $branch = actInBranch(Branch::factory()->create());
    seedRoles();
    withActor(makeUser($branch, 'cashier')->id);
    $before = publicTree();

    $file = app(Files::class)->put(imgJpeg(900, 600), 'receipt', (string) Str::uuid());

    expect(publicTree())->toBe($before)
        ->and(file_exists(public_path($file->key)))->toBeFalse()
        ->and(is_dir(public_path('storage/receipts')))->toBeFalse();
})->group('module:shared', 'feature');
