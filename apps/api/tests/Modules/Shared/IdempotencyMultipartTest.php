<?php

declare(strict_types=1);

use App\Shared\Http\Middleware\Idempotency;
use App\Shared\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ResolveTestContext;

/*
 * Step 4.2b, the multipart branch of Idempotency: the fingerprint of an upload is its field names, its fields and the sha256 of each file
 * (never the whole body), so the same key with the same file replays the stored response and the handler never runs twice, and the same
 * key with another file is 422 idempotency.key_reused.
 */

uses()->group('module:shared', 'feature');

beforeEach(function () {
    $this->handled = 0;
    Route::middleware([ResolveTestContext::class, 'idempotency'])->group(function (): void {
        Route::post('/_shared-test/upload', function (Request $r) {
            $this->handled++;

            return response()->json(['sha' => hash_file('sha256', $r->file('file')->getRealPath()), 'nonce' => Str::random(12)], 201);
        });
    });
    $this->branch = Branch::factory()->create();
    ResolveTestContext::reset($this->branch);
    $this->send = fn (string $key, string $bytes, string $uuid = 'u1') => $this->withHeaders(['Idempotency-Key' => $key, 'Accept' => 'application/json'])
        ->post('/_shared-test/upload', ['client_uuid' => $uuid, 'file' => UploadedFile::fake()->createWithContent('a.jpg', $bytes)]);
});

it('replays the same key with the same file and runs the handler once', function () {
    $key = (string) Str::uuid();
    $bytes = imgJpeg(200, 100);

    $first = ($this->send)($key, $bytes);
    $second = ($this->send)($key, $bytes);

    $first->assertStatus(201)->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
    $second->assertStatus(201)->assertHeader(Idempotency::REPLAYED_HEADER, 'true');
    expect($second->json())->toEqual($first->json())->and($this->handled)->toBe(1);
});

it('refuses the same key with another file, or another field, as 422 idempotency.key_reused', function () {
    $key = (string) Str::uuid();
    ($this->send)($key, imgJpeg(200, 100))->assertStatus(201);

    ($this->send)($key, imgJpeg(201, 100))->assertStatus(422)->assertJsonPath('message_key', 'idempotency.key_reused');
    ($this->send)($key, imgJpeg(200, 100), 'u2')->assertStatus(422)->assertJsonPath('message_key', 'idempotency.key_reused');
    expect($this->handled)->toBe(1);
});

it('treats two different keys with the same file as two requests', function () {
    $bytes = imgJpeg(200, 100);

    ($this->send)((string) Str::uuid(), $bytes)->assertStatus(201);
    ($this->send)((string) Str::uuid(), $bytes)->assertStatus(201);

    expect($this->handled)->toBe(2);
});

it('never decodes the picture a second time on a replay of POST /files', function () {
    Storage::fake(config('files.disk'));
    seedRoles();
    $branch = actInBranch(Branch::factory()->create());
    loginAs(makeUser($branch, 'cashier'));
    $key = (string) Str::uuid();
    $uuid = (string) Str::uuid();
    $bytes = imgJpeg(900, 600);
    $post = fn () => $this->withHeaders(['Idempotency-Key' => $key, 'Accept' => 'application/json'])
        ->post('/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('a.jpg', $bytes), 'purpose' => 'receipt', 'client_uuid' => $uuid]);

    $first = $post()->assertCreated();
    // a replay is answered from the snapshot before any controller runs: breaking the stored object proves nothing reads it again
    Storage::disk(config('files.disk'))->deleteDirectory('receipts');
    $second = $post();

    $second->assertCreated()->assertHeader(Idempotency::REPLAYED_HEADER, 'true');
    expect($second->json('id'))->toBe($first->json('id'))->and($second->json('url'))->toBe($first->json('url'))->and(DB::table('stored_files')->count())->toBe(1);
});
