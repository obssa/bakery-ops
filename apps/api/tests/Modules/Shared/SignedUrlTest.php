<?php

declare(strict_types=1);

use App\Shared\Files\Files;
use App\Shared\Files\StoredFile;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Step 4.2a: signedUrl is the one place that knows the driver. Local disk: a temporary signed route (files.show, served by Shared, no bearer).
 * S3 driver: the bucket's own temporary URL. The route itself grows its API siblings in step 4.2b.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->branch = actInBranch(Branch::factory()->create());
    seedRoles();
    withActor(makeUser($this->branch, 'cashier')->id);
    $this->file = app(Files::class)->put(imgJpeg(600, 400), 'receipt', (string) Str::uuid());
});

it('signs the files.show route on a local disk, by id, with the configured default ttl', function () {
    $url = app(Files::class)->signedUrl($this->file);
    $parts = parse_url($url);
    parse_str($parts['query'], $query);

    expect($parts['path'])->toBe('/api/v1/files/'.$this->file->id)
        ->and($query)->toHaveKeys(['expires', 'signature'])
        ->and(str_contains($url, $this->file->key))->toBeFalse()
        ->and((int) $query['expires'])->toBe(now()->addSeconds(300)->getTimestamp());
});

it('serves the JPEG with private headers until the ttl and not after', function () {
    $url = app(Files::class)->signedUrl($this->file, 120);

    $response = $this->get($url)->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->streamedContent())->toBe(Storage::disk(config('files.disk'))->get($this->file->key));

    $this->travel(90)->seconds(); // travel is cumulative; the margin keeps a slow machine from tipping it
    $this->get($url)->assertOk();
    $this->travel(40)->seconds();
    $this->get($url)->assertForbidden();
});

it('refuses an unsigned or altered request', function () {
    $url = app(Files::class)->signedUrl($this->file);
    $parts = parse_url($url);
    parse_str($parts['query'], $query);

    $this->get($parts['path'])->assertForbidden();
    foreach (array_keys($query) as $param) {
        $changed = $query;
        $changed[$param] .= '0';
        $this->get($parts['path'].'?'.http_build_query($changed))->assertForbidden();
    }
    $this->get('/api/v1/files/'.($this->file->id + 1).'?'.$parts['query'])->assertForbidden();
});

it('answers 404 for a valid signature on a deleted file or a missing object', function () {
    $url = app(Files::class)->signedUrl($this->file);
    Storage::disk(config('files.disk'))->delete($this->file->key);
    $this->get($url)->assertNotFound();

    $second = app(Files::class)->put(imgJpeg(600, 400), 'receipt', (string) Str::uuid());
    $secondUrl = app(Files::class)->signedUrl($second);
    app(Files::class)->delete($second);
    $this->get($secondUrl)->assertNotFound();
});

it('signs by key as well, and never another branch\'s file or key', function () {
    expect(app(Files::class)->signedUrl($this->file->key))->toContain('/api/v1/files/'.$this->file->id);

    $other = actInBranch(Branch::factory()->create());
    withActor(makeUser($other, 'cashier')->id);

    expect(fn () => app(Files::class)->signedUrl($this->file->key))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(Files::class)->signedUrl($this->file))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(Files::class)->signedUrl('receipts/nope.jpg'))->toThrow(ModelNotFoundException::class);
});

it('asks the bucket for a temporary URL when the row\'s disk is an s3 disk', function () {
    config(['filesystems.disks.files_s3' => [
        'driver' => 's3', 'key' => 'test-key', 'secret' => 'test-secret', 'region' => 'us-east-1', 'bucket' => 'receipts',
        'endpoint' => 'http://minio.test:9000', 'use_path_style_endpoint' => true, 'visibility' => 'private', 'throw' => true,
    ]]);
    StoredFile::query()->whereKey($this->file->id)->update(['disk' => 'files_s3']);

    $url = app(Files::class)->signedUrl(StoredFile::query()->findOrFail($this->file->id), 90);

    expect($url)->toStartWith('http://minio.test:9000/receipts/'.$this->file->key)
        ->and($url)->toContain('X-Amz-Signature=')->toContain('X-Amz-Expires=90')
        ->and($url)->not->toContain('/api/v1/files/');
});
