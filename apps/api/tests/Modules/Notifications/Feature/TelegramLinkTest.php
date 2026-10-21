<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\NotificationDrivers;

pest()->group('module:notifications', 'feature');

/*
 * POST /api/v1/notifications/telegram/link and POST /api/v1/telegram/webhook/{secret} (plan section 4). The caller asks for a
 * one-time code (kept 10 minutes in the cache, nothing in the database), opens the bot with it, and Telegram calls the
 * webhook with "/start <code>": that links the chat to the person who asked. The webhook is public by necessity (Telegram
 * sends no bearer token), so it is guarded by the secret in the path AND the X-Telegram-Bot-Api-Secret-Token header; anything
 * else, or a driver that is off, is a bare 404. It always answers 200 {} to a valid call, so Telegram does not retry.
 */

beforeEach(function () {
    seedRoles();
    NotificationDrivers::allOff();
    Http::preventStrayRequests();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->me = makeUser($this->branch, 'waiter');
    $this->amharic = makeUser($this->branch, 'chef', ['locale' => 'am']);
    $this->audits = fn (string $action): int => DB::table('audit_log')->where('action', $action)->count();
    $this->link = fn () => $this->withHeaders(idempotencyKey())->postJson('/api/v1/notifications/telegram/link');
    $this->update = fn (string $text, string|int $chat = 4815162342, string $type = 'private'): array => [
        'update_id' => 1001,
        'message' => ['message_id' => 7, 'from' => ['id' => $chat, 'is_bot' => false], 'chat' => ['id' => $chat, 'type' => $type], 'text' => $text],
    ];
    $this->hook = fn (array $update, ?string $secret = null, ?string $header = null) => $this->postJson(
        '/api/v1/telegram/webhook/'.($secret ?? $this->tg['secret']),
        $update,
        $header === '' ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $header ?? $this->tg['secret']],
    );
    loginAs($this->me);
});

it('answers 422 notifications.driver.disabled when telegram is off', function () {
    $response = ($this->link)()->assertStatus(422);

    expect($response->json('message_key'))->toBe('notifications.driver.disabled')
        ->and($response->json('driver'))->toBe('telegram')
        ->and($response->json('message_am'))->toMatch('/\p{Ethiopic}/u')
        ->and(($this->audits)('notification.telegram.link_requested'))->toBe(0);
});

it('hands out a one-time code, its expiry ten minutes ahead and the link that opens the bot with it, and audits the request', function () {
    $this->tg = NotificationDrivers::telegram('bakery_example_bot');
    $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00', 'UTC'));

    $response = ($this->link)()->assertCreated();

    expect($response->json('code'))->toMatch('/^[A-Z0-9]{10}$/')
        ->and($response->json('expires_at'))->toBe('2026-10-22T08:10:00+00:00')
        ->and($response->json('deep_link'))->toBe('https://t.me/bakery_example_bot?start='.$response->json('code'))
        ->and(array_keys($response->json()))->toBe(['code', 'expires_at', 'deep_link'])
        ->and(($this->audits)('notification.telegram.link_requested'))->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'notification.telegram.link_requested')->value('after'))->not->toContain($response->json('code'))
        ->and(NotificationEndpoint::query()->count())->toBe(0);
});

it('hands out a different code every time and replays the same answer for the same key', function () {
    $this->tg = NotificationDrivers::telegram();
    $headers = idempotencyKey();

    $a = $this->withHeaders($headers)->postJson('/api/v1/notifications/telegram/link')->assertCreated();
    $replay = $this->withHeaders($headers)->postJson('/api/v1/notifications/telegram/link')->assertCreated();
    $b = ($this->link)()->assertCreated();

    expect($replay->json())->toBe($a->json())
        ->and($b->json('code'))->not->toBe($a->json('code'))
        ->and($this->postJson('/api/v1/notifications/telegram/link')->assertStatus(400)->json('message_key'))->toBe('idempotency.key_required');
});

it('links the chat to the person who asked when Telegram calls the webhook with /start and the code, and tells them in their language', function () {
    $this->tg = NotificationDrivers::telegram();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $code = ($this->link)()->assertCreated()->json('code');
    app('auth')->forgetGuards();

    ($this->hook)(($this->update)("/start {$code}"))->assertOk()->assertExactJson([]);

    $endpoint = NotificationEndpoint::query()->firstOrFail();
    expect($endpoint->user_id)->toBe($this->me->id)
        ->and($endpoint->branch_id)->toBe($this->branch->id)
        ->and($endpoint->driver)->toBe('telegram')
        ->and($endpoint->address)->toBe('4815162342')
        ->and($endpoint->address_hash)->toBe(hash('sha256', '4815162342'))
        ->and($endpoint->verified_at)->not->toBeNull()
        ->and(DB::table('notification_endpoints')->value('address'))->not->toContain('4815162342')
        ->and(($this->audits)('notification.telegram.linked'))->toBe(1);
    $audit = DB::table('audit_log')->where('action', 'notification.telegram.linked')->first();
    expect($audit->actor_id)->toBe($this->me->id)->and($audit->branch_id)->toBe($this->branch->id)->and($audit->after)->not->toContain('4815162342');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
        && $request['chat_id'] === '4815162342'
        && str_contains($request['text'], 'linked'));
});

it('answers an Amharic reader in Amharic', function () {
    $this->tg = NotificationDrivers::telegram();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    loginAs($this->amharic);
    $code = ($this->link)()->assertCreated()->json('code');
    app('auth')->forgetGuards();

    ($this->hook)(($this->update)("/start {$code}", 777))->assertOk();

    Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '777' && preg_match('/\p{Ethiopic}/u', (string) $request['text']) === 1);
    expect(NotificationEndpoint::query()->firstOrFail()->user_id)->toBe($this->amharic->id);
});

it('uses a code once', function () {
    $this->tg = NotificationDrivers::telegram();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $code = ($this->link)()->assertCreated()->json('code');
    app('auth')->forgetGuards();

    ($this->hook)(($this->update)("/start {$code}"))->assertOk();
    ($this->hook)(($this->update)("/start {$code}", 999))->assertOk();

    expect(NotificationEndpoint::query()->count())->toBe(1)->and(NotificationEndpoint::query()->value('user_id'))->toBe($this->me->id);
});

it('does not link with a code that is wrong, expired or missing, and says so to the chat in both languages', function (string $text) {
    $this->tg = NotificationDrivers::telegram();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    app('auth')->forgetGuards();

    ($this->hook)(($this->update)($text))->assertOk()->assertExactJson([]);

    expect(NotificationEndpoint::query()->count())->toBe(0)->and(($this->audits)('notification.telegram.linked'))->toBe(0);
    Http::assertSent(fn (Request $request): bool => preg_match('/\p{Ethiopic}/u', (string) $request['text']) === 1 && preg_match('/[A-Za-z]/', (string) $request['text']) === 1);
})->with(['/start NOSUCHCODE', '/start', 'hello']);

it('does not link with a code after ten minutes', function () {
    $this->tg = NotificationDrivers::telegram();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00', 'UTC'));
    $code = ($this->link)()->assertCreated()->json('code');
    app('auth')->forgetGuards();

    $this->travelTo(CarbonImmutable::parse('2026-10-22 08:09:59', 'UTC'));
    $early = ($this->update)("/start {$code}");
    $this->travelTo(CarbonImmutable::parse('2026-10-22 08:10:01', 'UTC'));
    ($this->hook)($early)->assertOk();

    expect(NotificationEndpoint::query()->count())->toBe(0);
});

it('links with a code a second before it expires', function () {
    $this->tg = NotificationDrivers::telegram();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00', 'UTC'));
    $code = ($this->link)()->assertCreated()->json('code');
    app('auth')->forgetGuards();

    $this->travelTo(CarbonImmutable::parse('2026-10-22 08:09:59', 'UTC'));
    ($this->hook)(($this->update)("/start {$code}"))->assertOk();

    expect(NotificationEndpoint::query()->count())->toBe(1);
});

it('ignores a chat that is not private, and updates that are not messages', function () {
    $this->tg = NotificationDrivers::telegram();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $code = ($this->link)()->assertCreated()->json('code');
    app('auth')->forgetGuards();

    ($this->hook)(($this->update)("/start {$code}", -100123, 'supergroup'))->assertOk()->assertExactJson([]);
    ($this->hook)(['update_id' => 5, 'edited_message' => ['text' => "/start {$code}"]])->assertOk()->assertExactJson([]);
    ($this->hook)([])->assertOk()->assertExactJson([]);

    expect(NotificationEndpoint::query()->count())->toBe(0);
});

it('still answers 200 when the reply to the chat fails', function () {
    $this->tg = NotificationDrivers::telegram();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
    $code = ($this->link)()->assertCreated()->json('code');
    app('auth')->forgetGuards();

    ($this->hook)(($this->update)("/start {$code}"))->assertOk();

    expect(NotificationEndpoint::query()->count())->toBe(1);
});

it('keeps one live row when the same person links the same chat again, after a revoke too', function () {
    $this->tg = NotificationDrivers::telegram();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    foreach ([1, 2] as $round) {
        loginAs($this->me);
        $code = ($this->link)()->assertCreated()->json('code');
        app('auth')->forgetGuards();
        ($this->hook)(($this->update)("/start {$code}"))->assertOk();
        if ($round === 1) {
            NotificationEndpoint::query()->firstOrFail()->forceFill(['revoked_at' => now()])->save();
            NotificationEndpoint::query()->firstOrFail()->delete();
        }
    }

    $endpoint = NotificationEndpoint::query()->firstOrFail();
    expect(NotificationEndpoint::withTrashed()->count())->toBe(1)->and($endpoint->revoked_at)->toBeNull();
});

it('refuses with a bare 404 a wrong path secret, a wrong or missing header, and a driver that is off', function () {
    $this->tg = NotificationDrivers::telegram();
    Http::fake();
    $code = ($this->link)()->assertCreated()->json('code');
    app('auth')->forgetGuards();
    $update = ($this->update)("/start {$code}");

    ($this->hook)($update, 'a-wrong-secret')->assertNotFound()->assertContent('');
    ($this->hook)($update, null, 'a-wrong-secret')->assertNotFound()->assertContent('');
    ($this->hook)($update, null, '')->assertNotFound()->assertContent('');
    ($this->hook)($update, 'a-wrong-secret', 'a-wrong-secret')->assertNotFound();
    // the right path secret with the header of a different value of the same length
    ($this->hook)($update, null, strrev($this->tg['secret']))->assertNotFound();
    NotificationDrivers::allOff();
    ($this->hook)($update, $this->tg['secret'], $this->tg['secret'])->assertNotFound();

    expect(NotificationEndpoint::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses even a request that guesses the secret when no secret is configured', function () {
    NotificationDrivers::telegram();
    config(['notifications.drivers.telegram.webhook_secret' => '']);

    $this->postJson('/api/v1/telegram/webhook/', [])->assertNotFound();
    $this->postJson('/api/v1/telegram/webhook/anything', [], ['X-Telegram-Bot-Api-Secret-Token' => ''])->assertNotFound();
});

it('needs no bearer token and no Idempotency-Key, and is listed as a public route', function () {
    $this->tg = NotificationDrivers::telegram();
    Http::fake();
    app('auth')->forgetGuards();

    ($this->hook)(($this->update)('hello'))->assertOk();

    expect(publicRoutes())->toContain('api/v1/telegram/webhook/{secret}');
});
