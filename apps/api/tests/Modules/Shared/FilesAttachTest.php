<?php

declare(strict_types=1);

use App\Shared\Files\Files;
use App\Shared\Files\FilesError;
use App\Shared\Files\StoredFile;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses()->group('module:shared', 'feature');

/*
 * Step 4.3b: Files::attach claims an unattached file for what it proves (a payment now, a payment refund in 4.3d), inside the caller's
 * transaction: attached_type, attached_id and attached_at are written once. The orphan purge and DELETE /files never touch it afterwards.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->user = makeUser($this->branch, 'cashier');
    withActor($this->user->id);
    $this->file = fn (): StoredFile => app(Files::class)->put(imgJpeg(300, 200), 'receipt', (string) Str::uuid());
});

it('attaches a file to a payment once, with the time it was claimed', function () {
    $file = ($this->file)();

    app(Files::class)->attach($file, 'payment', 41);

    $row = StoredFile::query()->findOrFail($file->id);
    expect($row->attached_type)->toBe('payment')
        ->and($row->attached_id)->toBe(41)
        ->and($row->attached_at?->toDateTimeString())->toBe(now()->startOfSecond()->toDateTimeString());
});

it('refuses a file that is attached already (409 files.attached) and a target it does not know', function () {
    $file = ($this->file)();
    app(Files::class)->attach($file, 'payment', 41);

    expect(fn () => app(Files::class)->attach(StoredFile::query()->findOrFail($file->id), 'payment', 42))
        ->toThrow(FilesError::class, 'files.attached');
    expect(fn () => app(Files::class)->attach(($this->file)(), 'selfie', 1))->toThrow(InvalidArgumentException::class);
    expect(StoredFile::query()->findOrFail($file->id)->attached_id)->toBe(41);
});
