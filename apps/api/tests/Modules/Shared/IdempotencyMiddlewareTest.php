<?php

declare(strict_types=1);

use App\Shared\Http\Middleware\Idempotency;
use App\Shared\Models\Branch;
use App\Shared\Models\IdempotencyKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ResolveTestContext;

/*
 * Rule 7 / ADR 0003: Idempotency-Key on every write, scoped (branch, user, key), replayed from a stored snapshot,
 * guarded by a lock while in flight. The routes below exist only inside these tests.
 */

beforeEach(function () {
    // Each request starts with no scoped context, as in production (tests/TestCase.php), so the branch and the user are
    // resolved inside the request, the way authentication does, from what the test holds in ResolveTestContext.
    Route::middleware([ResolveTestContext::class, 'idempotency'])->group(function (): void {
        Route::post('/_shared-test/echo', fn (Request $r) => response()->json(['received' => $r->all(), 'nonce' => Str::random(12)], 201));
        Route::post('/_shared-test/fail', fn () => response()->json(['nonce' => Str::random(12)], 500));
        Route::post('/_shared-test/plain', fn () => response('plain '.Str::random(6), 200, ['Content-Type' => 'text/plain']));
        Route::get('/_shared-test/read', fn () => response()->json(['ok' => true]));
    });
    $this->branch = Branch::factory()->create();
    ResolveTestContext::reset($this->branch);
});

it('rejects a write without an Idempotency-Key with a bilingual 400', function () {
    $response = $this->postJson('/_shared-test/echo', ['a' => 1]);

    $response->assertStatus(400)->assertJsonPath('message_key', 'idempotency.key_required');
    expect($response->json('message_en'))->toBeString()->not->toBeEmpty()
        ->and($response->json('message_am'))->toBeString()->not->toBeEmpty()->not->toBe($response->json('message_en'))
        ->and(IdempotencyKey::query()->count())->toBe(0);
})->group('module:shared', 'feature');

it('rejects keys longer than 255 characters', function () {
    $this->withHeader('Idempotency-Key', str_repeat('k', 256))->postJson('/_shared-test/echo', ['a' => 1])
        ->assertStatus(400)->assertJsonPath('message_key', 'idempotency.key_required');
})->group('module:shared', 'feature');

it('lets reads through without a key', function () {
    $this->getJson('/_shared-test/read')->assertOk()->assertJsonPath('ok', true);
})->group('module:shared', 'feature');

it('runs the handler once and replays the stored response for the same key and body', function () {
    $key = (string) Str::uuid();

    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['qty' => '2.5000']);
    $second = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['qty' => '2.5000']);

    $first->assertStatus(201)->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
    $second->assertStatus(201)->assertHeader(Idempotency::REPLAYED_HEADER, 'true');
    expect($second->json())->toEqual($first->json())
        ->and($second->json('nonce'))->toBe($first->json('nonce'));

    $rows = IdempotencyKey::query()->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->branch_id)->toBe($this->branch->id)
        ->and($rows[0]->user_id)->toBe(7)
        ->and($rows[0]->key)->toBe($key)
        ->and($rows[0]->response_status)->toBe(201)
        ->and($rows[0]->expires_at->isBetween(now()->addHours(23), now()->addHours(25)))->toBeTrue();
})->group('module:shared', 'feature');

it('rejects the same key with a different body as 422', function () {
    $key = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['qty' => '1.0000'])->assertStatus(201);

    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['qty' => '9.0000'])
        ->assertStatus(422)->assertJsonPath('message_key', 'idempotency.key_reused');
    expect(IdempotencyKey::query()->count())->toBe(1);
})->group('module:shared', 'feature');

it('scopes keys per user', function () {
    $key = (string) Str::uuid();
    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 1]);

    ResolveTestContext::$userId = 8;
    $other = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 1]);

    $other->assertStatus(201)->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
    expect($other->json('nonce'))->not->toBe($first->json('nonce'))
        ->and(IdempotencyKey::query()->count())->toBe(2);
})->group('module:shared', 'feature');

it('scopes keys per branch', function () {
    $key = (string) Str::uuid();
    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 1]);

    ResolveTestContext::$branch = Branch::factory()->create();
    $other = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 1]);

    $other->assertStatus(201)->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
    expect($other->json('nonce'))->not->toBe($first->json('nonce'))
        ->and(IdempotencyKey::query()->count())->toBe(2);
})->group('module:shared', 'feature');

it('answers 409 while a request with the same key is still in flight', function () {
    $key = (string) Str::uuid();
    $lock = Cache::lock(Idempotency::lockName($this->branch->id, 7, $key), 30);
    expect($lock->get())->toBeTrue();

    try {
        $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 1])
            ->assertStatus(409)->assertJsonPath('message_key', 'idempotency.in_flight');
    } finally {
        $lock->release();
    }

    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 1])->assertStatus(201);
})->group('module:shared', 'feature');

it('treats an expired key as new', function () {
    $key = (string) Str::uuid();
    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 1]);
    IdempotencyKey::query()->update(['expires_at' => now()->subMinute()]);

    $again = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 1]);

    $again->assertStatus(201)->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
    expect($again->json('nonce'))->not->toBe($first->json('nonce'))
        ->and(IdempotencyKey::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->first()?->expires_at->isFuture())->toBeTrue();
})->group('module:shared', 'feature');

it('does not store server errors, so the client can retry', function () {
    $key = (string) Str::uuid();
    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/fail', []);
    $second = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/fail', []);

    $first->assertStatus(500);
    $second->assertStatus(500)->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
    expect($second->json('nonce'))->not->toBe($first->json('nonce'))
        ->and(IdempotencyKey::query()->count())->toBe(0);
})->group('module:shared', 'feature');

it('replays non-JSON responses verbatim', function () {
    $key = (string) Str::uuid();
    $first = $this->withHeader('Idempotency-Key', $key)->post('/_shared-test/plain');
    $second = $this->withHeader('Idempotency-Key', $key)->post('/_shared-test/plain');

    $second->assertOk()->assertHeader(Idempotency::REPLAYED_HEADER, 'true');
    expect($second->getContent())->toBe($first->getContent());
})->group('module:shared', 'feature');

it('refuses to run before the branch and the actor are resolved', function () {
    $this->withoutExceptionHandling();
    ResolveTestContext::$resolved = false;

    expect(fn () => $this->withHeader('Idempotency-Key', 'k1')->postJson('/_shared-test/echo', []))
        ->toThrow(LogicException::class);
})->group('module:shared', 'feature');

/*
 * Item 14 of the 2026-11-20 state-machine review (ADR 0031): the lock lives lock_seconds, so a handler slower than that used to let a
 * duplicate take the lock, find no answer and run the write a second time. The key's row is now written "in flight" before the handler
 * runs: a duplicate that finds it without an answer gets 409, also after the lock ran out, until the row is older than
 * abandon_after_seconds (its request died, and the key may run again).
 */
it('answers 409 to a duplicate that arrives after a slow first request outlived its lock, and runs the write once', function () {
    $key = (string) Str::uuid();
    $lockName = Idempotency::lockName($this->branch->id, 7, $key);
    $runs = 0;
    $duplicate = null;
    Route::middleware([ResolveTestContext::class, 'idempotency'])->post('/_shared-test/slow', function (Request $request) use (&$runs, &$duplicate, $key, $lockName) {
        $runs++;
        // the handler is slower than lock_seconds: the lock runs out while it is still working ...
        Cache::lock($lockName)->forceRelease();
        // ... and the client's retry of the same write arrives now, on another worker
        $retry = Request::create('/_shared-test/slow', 'POST', $request->all());
        $retry->headers->set(Idempotency::HEADER, $key);
        $duplicate = app(Idempotency::class)->handle($retry, function () use (&$runs) {
            $runs++;

            return response()->json(['ran' => 'twice'], 201);
        });

        return response()->json(['nonce' => Str::random(12)], 201);
    });

    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/slow', ['qty' => '1.0000']);

    $first->assertStatus(201);
    expect($duplicate?->getStatusCode())->toBe(409)
        ->and(json_decode((string) $duplicate?->getContent(), true)['message_key'] ?? null)->toBe('idempotency.in_flight')
        ->and($runs)->toBe(1, 'the duplicate never ran the write');
    expect(IdempotencyKey::query()->sole()->response_status)->toBe(201);

    // once the first answer is stored, the retry replays it and the write still ran once
    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/slow', ['qty' => '1.0000'])
        ->assertStatus(201)->assertHeader(Idempotency::REPLAYED_HEADER, 'true')->assertJsonPath('nonce', $first->json('nonce'));
    expect($runs)->toBe(1);
})->group('module:shared', 'feature');

it('keeps a key whose request has no answer yet in flight until abandon_after_seconds, then lets it run again', function () {
    $key = (string) Str::uuid();
    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 1])->assertStatus(201);
    $abandonAfter = (int) config('bakery.idempotency.abandon_after_seconds');
    // the row as a request that died after writing it would have left it: no answer
    $inFlight = fn (int $secondsAgo) => IdempotencyKey::query()->toBase()->update([
        'response_status' => null, 'response_body' => null, 'updated_at' => now()->subSeconds($secondsAgo),
    ]);

    $inFlight($abandonAfter - 5);
    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 1])
        ->assertStatus(409)->assertJsonPath('message_key', 'idempotency.in_flight');

    $inFlight($abandonAfter + 5);
    $again = $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 1]);

    $again->assertStatus(201)->assertHeaderMissing(Idempotency::REPLAYED_HEADER);
    $row = IdempotencyKey::query()->sole();
    expect($again->json('nonce'))->not->toBe($first->json('nonce'))
        ->and($row->response_status)->toBe(201)
        ->and($row->updated_at?->isAfter(now()->subMinute()))->toBeTrue();
})->group('module:shared', 'feature');

it('still answers 422 for a different body under a key that is in flight', function () {
    $key = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 1])->assertStatus(201);
    IdempotencyKey::query()->toBase()->update(['response_status' => null, 'response_body' => null, 'updated_at' => now()]);

    $this->withHeader('Idempotency-Key', $key)->postJson('/_shared-test/echo', ['a' => 2])
        ->assertStatus(422)->assertJsonPath('message_key', 'idempotency.key_reused');
})->group('module:shared', 'feature');

it('keeps a request in flight for longer than its lock lives', function () {
    expect(config('bakery.idempotency.abandon_after_seconds'))->toBeInt()
        ->toBeGreaterThan(config('bakery.idempotency.lock_seconds'));
})->group('module:shared', 'unit');
