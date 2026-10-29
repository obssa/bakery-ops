<?php

declare(strict_types=1);

use App\Shared\Http\Middleware\Idempotency;
use App\Shared\Models\Branch;
use App\Shared\Models\IdempotencyKey;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ResolveTestContext;

/*
 * Step 4.3e, the security fix of the Idempotency fingerprint. The middleware stores a sha256 of the request as request_hash. On the PIN routes
 * (POST /orders/{order}/void, POST /payments/{payment}/void) and the People writes (a password, a short code) that body held a secret, so whoever
 * could read idempotency_keys could recover a 4 to 6 digit PIN offline by hashing the rest of the body with every PIN. Now:
 *   - the secret fields (Idempotency::SECRET_FIELDS, at any depth) are left out of the fingerprint: the stored hash is the hash of the body
 *     without them, and the same key with every other field equal is the same request whatever secret came with it;
 *   - a request that carried a secret stores only a success: a refusal (a wrong PIN above all) writes nothing, so it is not kept and the same
 *     key with the right PIN is checked again;
 *   - an answer that carries a secret (the short-code reset hands the new code back) is stored encrypted and replayed unchanged;
 *   - a multipart upload (step 4.2b) is fingerprinted exactly as before.
 * The routes below exist only inside these tests.
 */

uses()->group('module:shared', 'feature');

beforeEach(function () {
    $this->handled = 0;
    Route::middleware([ResolveTestContext::class, 'idempotency'])->group(function (): void {
        Route::post('/_shared-test/approve', function (Request $r) {
            $this->handled++;
            if ($r->input('pin') !== '2468') {
                return response()->json(['message_key' => 'identity.pin.rejected', 'nonce' => Str::random(12)], 403);
            }

            return response()->json(['approved' => true, 'nonce' => Str::random(12)]);
        });
        Route::post('/_shared-test/refuse', function () {
            $this->handled++;

            return response()->json(['message_key' => 'orders.order.not_voidable', 'nonce' => Str::random(12)], 409);
        });
        Route::post('/_shared-test/reset', function () {
            $this->handled++;

            return response()->json(['user_id' => 5, 'short_code' => (string) random_int(100000, 999999), 'nonce' => Str::random(12)]);
        });
        Route::post('/_shared-test/upload', function () {
            $this->handled++;

            return response()->json(['nonce' => Str::random(12)], 201);
        });
    });
    $this->branch = Branch::factory()->create();
    ResolveTestContext::reset($this->branch);
    $this->hashOf = fn (string $path, array $input): string => hash('sha256', 'POST '.$path.' '.json_encode(
        $input,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ));
    $this->stored = fn (string $key): ?IdempotencyKey => IdempotencyKey::query()->where('key', $key)->first();
});

it('names the secret fields in one place', function () {
    // step 5.1a: `token`, the bridge token POST /devices hands back once (its answer is stored encrypted, phase-5 plan 4.1)
    expect(Idempotency::SECRET_FIELDS)->toBe(['password', 'pin', 'short_code', 'token']);
});

it('leaves the PIN out of the stored hash: it is the hash of the body without it, and the body with it does not reproduce it', function () {
    $key = (string) Str::uuid();

    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/approve', ['reason' => 'Keyed twice', 'pin' => '2468'])->assertOk();

    $hash = ($this->stored)($key)?->request_hash;
    expect($hash)->toBe(($this->hashOf)('_shared-test/approve', ['reason' => 'Keyed twice']))
        ->and($hash)->not->toBe(($this->hashOf)('_shared-test/approve', ['pin' => '2468', 'reason' => 'Keyed twice']));
    // nobody can find the PIN by hashing the rest of the body with each of the 10^4 four-digit candidates
    $found = null;
    foreach (range(0, 9999) as $n) {
        $pin = str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        if (hash_equals((string) $hash, ($this->hashOf)('_shared-test/approve', ['pin' => $pin, 'reason' => 'Keyed twice']))) {
            $found = $pin;
            break;
        }
    }
    expect($found)->toBeNull();
});

it('leaves out every secret field at any depth, and keeps every other field', function () {
    $key = (string) Str::uuid();
    $body = ['name' => 'Sara', 'password' => 'correct-horse', 'short_code' => '1357', 'rows' => [['pin' => '2468', 'qty' => '1.0000']]];

    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/reset', $body)->assertOk();

    expect(($this->stored)($key)?->request_hash)->toBe(($this->hashOf)('_shared-test/reset', ['name' => 'Sara', 'rows' => [['qty' => '1.0000']]]));
    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/reset', ['name' => 'Sarah'] + $body)
        ->assertStatus(422)->assertJsonPath('message_key', 'idempotency.key_reused');
});

it('replays a success for the same key whatever PIN comes with it, and the handler runs once', function () {
    $key = (string) Str::uuid();

    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/approve', ['reason' => 'x', 'pin' => '2468'])->assertOk();
    $second = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/approve', ['reason' => 'x', 'pin' => '0000']);

    $second->assertOk()->assertHeader(Idempotency::REPLAYED_HEADER, 'true');
    expect($second->json())->toBe($first->json())->and($this->handled)->toBe(1);
});

it('keeps no refusal of a request that carried a PIN, so the same key with the right PIN is checked again', function () {
    $key = (string) Str::uuid();

    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/approve', ['reason' => 'x', 'pin' => '0000'])
        ->assertStatus(403)->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
    expect(($this->stored)($key))->toBeNull();

    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/approve', ['reason' => 'x', 'pin' => '1111'])
        ->assertStatus(403)->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
    $right = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/approve', ['reason' => 'x', 'pin' => '2468']);
    $right->assertOk()->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
    expect($this->handled)->toBe(3);

    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/approve', ['reason' => 'x', 'pin' => '0000'])
        ->assertOk()->assertHeader(Idempotency::REPLAYED_HEADER, 'true')->assertJsonPath('nonce', $right->json('nonce'));
    expect($this->handled)->toBe(3);
});

it('still keeps and replays a refusal of a request without a secret', function () {
    $key = (string) Str::uuid();

    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/refuse', ['reason' => 'x'])->assertStatus(409);
    $second = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/refuse', ['reason' => 'x']);

    $second->assertStatus(409)->assertHeader(Idempotency::REPLAYED_HEADER, 'true');
    expect($second->json('nonce'))->toBe($first->json('nonce'))->and($this->handled)->toBe(1);
});

it('stores an answer that carries a secret encrypted, and replays it unchanged', function () {
    $key = (string) Str::uuid();

    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/reset', ['user' => 5])->assertOk();
    $code = (string) $first->json('short_code');
    $second = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/reset', ['user' => 5]);

    $second->assertOk()->assertHeader(Idempotency::REPLAYED_HEADER, 'true');
    expect($second->json())->toBe($first->json())->and($this->handled)->toBe(1);
    $raw = (string) DB::table('idempotency_keys')->where('key', $key)->value('response_body');
    expect($raw)->not->toContain($code)->not->toContain('short_code')
        ->and(json_decode($raw, true)['kind'] ?? null)->toBe('encrypted');
});

it('refuses the key, and runs nothing, when a stored encrypted answer can no longer be read', function () {
    $key = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/reset', ['user' => 5])->assertOk();
    DB::table('idempotency_keys')->where('key', $key)->update(['response_body' => json_encode(['kind' => 'encrypted', 'data' => 'not-a-ciphertext'])]);

    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/reset', ['user' => 5])
        ->assertStatus(422)->assertJsonPath('message_key', 'idempotency.key_reused');
    expect($this->handled)->toBe(1);
});

it('fingerprints a multipart upload exactly as before: its fields and the sha256 of each file', function () {
    $key = (string) Str::uuid();
    $bytes = imgJpeg(120, 80);
    $send = fn () => $this->withHeaders(['Idempotency-Key' => $key, 'Accept' => 'application/json'])
        ->post('/_shared-test/upload', ['client_uuid' => 'u1', 'purpose' => 'receipt', 'file' => UploadedFile::fake()->createWithContent('a.jpg', $bytes)]);

    $send()->assertCreated();
    $send()->assertCreated()->assertHeader(Idempotency::REPLAYED_HEADER, 'true');

    expect(($this->stored)($key)?->request_hash)->toBe(($this->hashOf)('_shared-test/upload', [
        'client_uuid' => 'u1',
        'file' => ['uploaded_file_sha256' => hash('sha256', $bytes)],
        'purpose' => 'receipt',
    ]))->and($this->handled)->toBe(1);
});

it('keeps no answer with a secret when the app key cannot encrypt it, and still answers', function () {
    // e2e-p2 found it: the compose stack's APP_KEY was 35 bytes, which AES-256 refuses, and the short-code reset answered 500
    config(['app.key' => 'base64:'.base64_encode('too-short-for-aes')]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
    Log::spy();
    $key = (string) Str::uuid();

    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/reset', ['user' => 5]);
    $second = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/reset', ['user' => 5]);

    $first->assertOk()->assertJsonStructure(['short_code']);
    $second->assertOk()->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
    expect(($this->stored)($key))->toBeNull()->and($this->handled)->toBe(2);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'not stored')
        && ! str_contains(json_encode($context), (string) $first->json('short_code')))->twice();
});
