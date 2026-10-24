<?php

declare(strict_types=1);

use App\Shared\Files\Files;
use App\Shared\Files\FilesError;
use App\Shared\Files\StoredFile;
use App\Shared\Models\Branch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Step 4.2a: Files::put / stream / exists / delete over the configured disk. The bytes stored are the processed JPEG; the row records
 * where, how big, the sha256 of the stored bytes and who put it; the original file name is never kept.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->branch = actInBranch(Branch::factory()->create());
    seedRoles();
    $this->user = makeUser($this->branch, 'cashier');
    withActor($this->user->id);
    $this->disk = Storage::disk(config('files.disk'));
});

it('stores the processed JPEG under receipts/{branch}/{yyyy}/{mm}/{uuid}.jpg and records the row', function () {
    $uuid = (string) Str::uuid();
    $file = app(Files::class)->put(imgInject(imgJpeg(3000, 2000), imgExifApp1(1)), 'receipt', $uuid);
    $stored = $this->disk->get($file->key);

    expect($file)->toBeInstanceOf(StoredFile::class)
        ->and($file->key)->toMatch('#^receipts/'.$this->branch->id.'/\d{4}/\d{2}/[0-9a-f-]{36}\.jpg$#')
        ->and($file->key)->toContain(now()->format('Y/m'))
        ->and($file->purpose)->toBe('receipt')
        ->and($file->disk)->toBe(config('files.disk'))
        ->and($file->mime)->toBe('image/jpeg')
        ->and($file->bytes)->toBe(strlen($stored))
        ->and([$file->width, $file->height])->toBe([1600, 1067])
        ->and($file->sha256)->toBe(hash('sha256', $stored))
        ->and($file->client_uuid)->toBe($uuid)
        ->and($file->branch_id)->toBe($this->branch->id)
        ->and($file->uploaded_by_user_id)->toBe($this->user->id)
        ->and($file->attached_type)->toBeNull()->and($file->attached_id)->toBeNull()->and($file->attached_at)->toBeNull();
});

it('takes an UploadedFile as well and never keeps its name', function () {
    $upload = UploadedFile::fake()->createWithContent('my-secret-receipt-name.jpg', imgJpeg(800, 600));

    $file = app(Files::class)->put($upload, 'refund_proof', (string) Str::uuid());

    expect($file->purpose)->toBe('refund_proof')
        ->and($file->key)->not->toContain('secret')
        ->and(json_encode(DB::table('stored_files')->first()))->not->toContain('secret')
        ->and($this->disk->allFiles())->toBe([$file->key]);
});

it('refuses an unknown purpose and stores nothing', function () {
    expect(fn () => app(Files::class)->put(imgJpeg(100, 100), 'selfie', (string) Str::uuid()))->toThrow(InvalidArgumentException::class)
        ->and($this->disk->allFiles())->toBe([])->and(DB::table('stored_files')->count())->toBe(0);
});

it('refuses a file over max_bytes before decoding it', function () {
    config(['files.max_bytes' => 1000]);

    try {
        app(Files::class)->put(imgJpeg(800, 600), 'receipt', (string) Str::uuid());
        $key = null;
    } catch (FilesError $e) {
        $key = $e->messageKey;
    }

    expect($key)->toBe('files.too_large')->and($this->disk->allFiles())->toBe([]);
});

it('is idempotent on client_uuid per branch and does not process the second upload', function () {
    $uuid = (string) Str::uuid();
    $first = app(Files::class)->put(imgJpeg(600, 400), 'receipt', $uuid);
    $second = app(Files::class)->put('this would be refused if it were looked at', 'receipt', $uuid);

    expect($second->id)->toBe($first->id)->and($this->disk->allFiles())->toHaveCount(1);

    $other = actInBranch(Branch::factory()->create());
    withActor(makeUser($other, 'cashier')->id);
    $third = app(Files::class)->put(imgJpeg(600, 400), 'receipt', $uuid);

    expect($third->id)->not->toBe($first->id)->and($third->branch_id)->toBe($other->id)->and($this->disk->allFiles())->toHaveCount(2);
});

it('streams, reports existence and deletes (row soft-deleted, object removed)', function () {
    $files = app(Files::class);
    $file = $files->put(imgJpeg(600, 400), 'receipt', (string) Str::uuid());

    $handle = $files->stream($file);
    expect(is_resource($handle))->toBeTrue()
        ->and(stream_get_contents($handle))->toBe($this->disk->get($file->key))
        ->and($files->exists($file))->toBeTrue();

    $files->delete($file);

    expect($files->exists($file))->toBeFalse()
        ->and($this->disk->exists($file->key))->toBeFalse()
        ->and(StoredFile::query()->find($file->id))->toBeNull()
        ->and(StoredFile::withTrashed()->find($file->id)?->deleted_at)->not->toBeNull();
});

it('reads an object from the disk its row names, not the configured one', function () {
    $files = app(Files::class);
    $file = $files->put(imgJpeg(600, 400), 'receipt', (string) Str::uuid());

    config(['files.disk' => 'files_s3']);

    expect($files->exists($file))->toBeTrue()
        ->and(stream_get_contents($files->stream($file)))->toBe($this->disk->get($file->key));
});
