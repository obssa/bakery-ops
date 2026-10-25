<?php

declare(strict_types=1);

use App\Shared\Files\Files;
use App\Shared\Files\StoredFile;
use App\Shared\Models\Branch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Step 4.2b, the portability job `files-s3` (phase-4 plan 10.1 R-F, 10.9 item 1): the same Files contract against a real S3-compatible store.
 * It runs only in CI, in the e2e compose stack with MinIO (`.github/workflows/ci.yml` job files-s3, `compose.ci.yml` minio + mc), never in
 * a module job and never against the live host (which has no bucket): the group is `files-s3` alone, and the tests skip unless FILES_S3=1.
 *
 * The two hosts (traps.md): the app container signs and fetches against AWS_ENDPOINT (http://minio:9000); a browser outside the stack would
 * use AWS_URL (http://localhost:9000), which is why the test fetches from inside the container, with the host the URL was signed for.
 */

uses()->group('files-s3');

beforeEach(function () {
    if (getenv('FILES_S3') !== '1') {
        $this->markTestSkipped('files-s3 runs only in the CI portability job (FILES_S3=1, MinIO reachable at AWS_ENDPOINT).');
    }
    config(['files.disk' => 'files_s3']);
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->user = makeUser($this->branch, 'cashier');
    withActor($this->user->id);
    $this->disk = Storage::disk('files_s3');
});

it('puts the processed JPEG in the bucket under the same key shape as the local disk', function () {
    $file = app(Files::class)->put(imgJpeg(2400, 1600), 'receipt', (string) Str::uuid());

    expect($file->disk)->toBe('files_s3')
        ->and($file->key)->toMatch('#^receipts/'.$this->branch->id.'/\d{4}/\d{2}/[0-9a-f-]{36}\.jpg$#')
        ->and($this->disk->exists($file->key))->toBeTrue()
        ->and(hash('sha256', $this->disk->get($file->key)))->toBe($file->sha256)
        ->and(app(Files::class)->exists($file))->toBeTrue();

    app(Files::class)->delete($file);
});

it('hands out a presigned URL that serves the bytes over HTTP, and nothing without its signature', function () {
    $file = app(Files::class)->put(imgJpeg(900, 600), 'receipt', (string) Str::uuid());

    $url = app(Files::class)->signedUrl($file, 60);
    $served = Http::get($url);
    $bare = Http::get(strstr($url, '?', true));
    $tampered = Http::get(preg_replace('/X-Amz-Signature=[0-9a-f]+/', 'X-Amz-Signature='.str_repeat('0', 64), $url));

    expect($url)->toContain('X-Amz-Signature=')->toContain('X-Amz-Expires=')
        ->and($served->status())->toBe(200)
        ->and(hash('sha256', $served->body()))->toBe($file->sha256)
        ->and($served->header('Content-Type'))->toContain('image/jpeg')
        ->and($bare->status())->toBeIn([401, 403])
        ->and($tampered->status())->toBe(403);

    app(Files::class)->delete($file);
});

it('stops serving an expired URL', function () {
    $file = app(Files::class)->put(imgJpeg(300, 200), 'receipt', (string) Str::uuid());
    $url = app(Files::class)->signedUrl($file, 1);
    expect(Http::get($url)->status())->toBe(200);

    sleep(3);

    expect(Http::get($url)->status())->toBe(403);
    app(Files::class)->delete($file);
});

it('deletes the object and soft-deletes the row', function () {
    $file = app(Files::class)->put(imgJpeg(300, 200), 'receipt', (string) Str::uuid());
    $url = app(Files::class)->signedUrl($file, 60);

    app(Files::class)->delete($file);

    expect($this->disk->exists($file->key))->toBeFalse()
        ->and(StoredFile::query()->find($file->id))->toBeNull()
        ->and(Http::get($url)->status())->toBe(404);
});

it('answers the files API from the bucket: upload, fresh url, delete', function () {
    loginAs($this->user);
    $upload = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
        ->post('/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('s.jpg', imgJpeg(800, 600)), 'purpose' => 'receipt', 'client_uuid' => (string) Str::uuid()])
        ->assertCreated();
    $id = $upload->json('id');

    $url = $this->getJson("/api/v1/files/{$id}/url")->assertOk()->json('url');
    expect($url)->toContain('X-Amz-Signature=')
        ->and(hash('sha256', Http::get($url)->body()))->toBe($upload->json('sha256'));

    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/files/{$id}")->assertNoContent();
    expect(Http::get($url)->status())->toBe(404);
});
