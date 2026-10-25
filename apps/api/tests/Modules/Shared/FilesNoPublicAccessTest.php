<?php

declare(strict_types=1);

use App\Shared\Files\Files;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Step 4.2b: there is no plain-URL way to a stored image. The object is never under public/, public/storage carries no files, and a GET of
 * /files/{id} with no signature (or a wrong one) is 403 files.invalid_signature, whatever the id.
 */

uses()->group('module:shared', 'feature');

beforeEach(function () {
    Storage::fake(config('files.disk'));
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'cashier')->id);
    $this->file = app(Files::class)->put(imgJpeg(400, 300), 'receipt', (string) Str::uuid());
});

it('keeps the object path outside public/', function () {
    $root = (string) config('filesystems.disks.files.root');

    expect($root)->not->toStartWith(public_path())
        ->and(realpath(public_path()) ?: public_path())->not->toBe($root)
        ->and(file_exists(public_path($this->file->key)))->toBeFalse();
});

it('has no files folder in public/storage', function () {
    $storage = public_path('storage');

    expect(is_dir($storage.'/files') || is_dir($storage.'/receipts') || is_dir($storage.'/private'))->toBeFalse();
    if (is_dir($storage)) {
        expect(glob($storage.'/receipts*') ?: [])->toBe([]);
    }
});

it('answers a plain GET of /files/{id} without a signature with 403 files.invalid_signature', function () {
    $this->getJson("/api/v1/files/{$this->file->id}")->assertForbidden()->assertJsonPath('message_key', 'files.invalid_signature');
    $this->get("/api/v1/files/{$this->file->id}")->assertForbidden();
});

it('refuses a wrong or tampered signature, and an id the signature was not made for', function () {
    $url = app(Files::class)->signedUrl($this->file);
    $other = app(Files::class)->put(imgJpeg(300, 200), 'receipt', (string) Str::uuid());

    $this->get($url)->assertOk();
    $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature=00', $url))->assertForbidden();
    $this->get(str_replace("/files/{$this->file->id}?", "/files/{$other->id}?", $url))->assertForbidden();
});

it('serves nothing through the framework storage route either', function () {
    // the framework route of the default local disk answers 403 (no signature) or 404; either way no bytes
    foreach (['/storage/'.$this->file->key, '/storage/files/'.$this->file->key] as $path) {
        expect(in_array($this->get($path)->getStatusCode(), [403, 404], true))->toBeTrue();
    }
});
