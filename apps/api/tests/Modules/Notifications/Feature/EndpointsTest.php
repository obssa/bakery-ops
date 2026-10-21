<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationDrivers;

pest()->group('module:notifications', 'feature');

/*
 * GET /api/v1/notifications/channels, POST /api/v1/notifications/endpoints and DELETE /api/v1/notifications/endpoints/{endpoint}
 * (notifications.view, every role; plan section 4): where the caller can be reached outside the app. A push subscription is
 * stored encrypted, one live row per person and subscription; a driver that is off answers 422 notifications.driver.disabled
 * and the channels answer says so, so the web hides the control. Endpoints are always the caller's own.
 */

beforeEach(function () {
    seedRoles();
    NotificationDrivers::allOff();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->me = makeUser($this->branch, 'waiter');
    $this->colleague = makeUser($this->branch, 'chef');
    $this->other = Branch::factory()->create();
    $this->stranger = makeUser($this->other, 'waiter');
    $this->subscription = fn (string $endpoint = 'https://push.example.test/send/abc123'): array => [
        'driver' => 'push',
        'subscription' => ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKeyOfTheBrowser_example-only', 'auth' => 'authSecretExample']],
        'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130',
    ];
    $this->subscribe = fn (?array $body = null) => $this->withHeaders(idempotencyKey())->postJson('/api/v1/notifications/endpoints', $body ?? ($this->subscription)());
    $this->audits = fn (string $action): int => DB::table('audit_log')->where('action', $action)->count();
    loginAs($this->me);
});

it('says that push and telegram are off, with no key and not subscribed, while only the inbox is on', function () {
    $this->getJson('/api/v1/notifications/channels')->assertOk()->assertExactJson([
        'in_app' => true,
        'push' => ['enabled' => false, 'public_key' => null, 'subscribed' => false],
        'telegram' => ['enabled' => false, 'bot_username' => null, 'linked' => false],
    ]);
});

it('serves the public push key and the bot name only when those drivers are on, and says whether the caller is subscribed or linked', function () {
    $keys = NotificationDrivers::push();
    $telegram = NotificationDrivers::telegram('bakery_example_bot');

    $this->getJson('/api/v1/notifications/channels')->assertOk()->assertExactJson([
        'in_app' => true,
        'push' => ['enabled' => true, 'public_key' => $keys['publicKey'], 'subscribed' => false],
        'telegram' => ['enabled' => true, 'bot_username' => 'bakery_example_bot', 'linked' => false],
    ]);
    NotificationEndpoint::factory()->push()->create(['branch_id' => $this->branch->id, 'user_id' => $this->me->id]);
    NotificationEndpoint::factory()->telegram()->create(['branch_id' => $this->branch->id, 'user_id' => $this->me->id]);

    $response = $this->getJson('/api/v1/notifications/channels')->assertOk();
    expect($response->json('push.subscribed'))->toBeTrue()
        ->and($response->json('telegram.linked'))->toBeTrue()
        ->and(json_encode($response->json()))->not->toContain($keys['privateKey'])->not->toContain($telegram['token'])->not->toContain($telegram['secret']);
});

it('does not count a revoked endpoint, or somebody elses, as subscribed', function () {
    NotificationDrivers::push();
    NotificationEndpoint::factory()->push()->create(['branch_id' => $this->branch->id, 'user_id' => $this->me->id, 'revoked_at' => now()]);
    NotificationEndpoint::factory()->push()->create(['branch_id' => $this->branch->id, 'user_id' => $this->colleague->id]);

    expect($this->getJson('/api/v1/notifications/channels')->json('push.subscribed'))->toBeFalse();
});

it('says the CI array driver is on for both channels, without a key', function () {
    NotificationDrivers::array();

    $response = $this->getJson('/api/v1/notifications/channels')->assertOk();

    expect($response->json('push.enabled'))->toBeTrue()->and($response->json('push.public_key'))->toBeNull()
        ->and($response->json('telegram.enabled'))->toBeTrue()->and($response->json('telegram.bot_username'))->toBeNull();
});

it('stores a push subscription for the caller, encrypted, and answers 201 with its id and driver', function () {
    NotificationDrivers::push();

    $response = ($this->subscribe)()->assertCreated();

    $endpoint = NotificationEndpoint::query()->firstOrFail();
    expect($response->json())->toBe(['id' => $endpoint->id, 'driver' => 'push'])
        ->and($endpoint->user_id)->toBe($this->me->id)
        ->and($endpoint->branch_id)->toBe($this->branch->id)
        ->and($endpoint->driver)->toBe('push')
        ->and($endpoint->address_hash)->toBe(hash('sha256', 'https://push.example.test/send/abc123'))
        ->and($endpoint->user_agent)->toBe('Mozilla/5.0 (Linux; Android 14) Chrome/130')
        ->and($endpoint->verified_at)->not->toBeNull()
        ->and($endpoint->revoked_at)->toBeNull()
        ->and(json_decode($endpoint->address, true))->toBe(($this->subscription)()['subscription'])
        ->and(DB::table('notification_endpoints')->value('address'))->not->toContain('push.example.test')
        ->and(($this->audits)('notification.endpoint.created'))->toBe(1);
    expect(DB::table('audit_log')->where('action', 'notification.endpoint.created')->value('after'))->not->toContain('p256dh')->not->toContain('push.example.test');
});

it('answers 200 with the same id when the same subscription is sent again, and keeps one row', function () {
    NotificationDrivers::push();
    $first = ($this->subscribe)()->assertCreated();

    $second = ($this->subscribe)()->assertOk();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and(NotificationEndpoint::query()->count())->toBe(1)
        ->and(($this->audits)('notification.endpoint.created'))->toBe(1);
});

it('refreshes the keys of a subscription that comes again with new ones', function () {
    NotificationDrivers::push();
    ($this->subscribe)()->assertCreated();
    $body = ($this->subscription)();
    $body['subscription']['keys']['auth'] = 'aNewAuthSecretExample';

    ($this->subscribe)($body)->assertOk();

    expect(json_decode(NotificationEndpoint::query()->firstOrFail()->address, true)['keys']['auth'])->toBe('aNewAuthSecretExample');
});

it('gives a person who subscribes again after revoking the same row back, live', function () {
    NotificationDrivers::push();
    $id = ($this->subscribe)()->assertCreated()->json('id');
    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/notifications/endpoints/{$id}")->assertOk();

    $again = ($this->subscribe)()->assertCreated();

    $endpoint = NotificationEndpoint::query()->firstOrFail();
    expect($again->json('id'))->toBe($id)
        ->and($endpoint->revoked_at)->toBeNull()
        ->and($endpoint->deleted_at)->toBeNull()
        ->and(NotificationEndpoint::withTrashed()->count())->toBe(1);
});

it('gives each person their own row for the same browser subscription', function () {
    NotificationDrivers::push();
    ($this->subscribe)()->assertCreated();
    loginAs($this->colleague);

    ($this->subscribe)()->assertCreated();

    expect(NotificationEndpoint::query()->count())->toBe(2)
        ->and(NotificationEndpoint::query()->pluck('user_id')->sort()->values()->all())->toBe([$this->me->id, $this->colleague->id]);
});

it('answers 422 notifications.driver.disabled in both languages when push is off, and stores nothing', function () {
    $response = ($this->subscribe)()->assertStatus(422);

    expect($response->json('message_key'))->toBe('notifications.driver.disabled')
        ->and($response->json('message_en'))->toBeString()->not->toBe('notifications.driver.disabled')
        ->and($response->json('message_am'))->toMatch('/\p{Ethiopic}/u')
        ->and($response->json('driver'))->toBe('push')
        ->and(NotificationEndpoint::query()->count())->toBe(0)
        ->and(($this->audits)('notification.endpoint.created'))->toBe(0);
});

it('answers 422 validation.failed for a subscription that is not one', function (array $change) {
    NotificationDrivers::push();
    $body = array_replace_recursive(($this->subscription)(), $change);
    foreach ($change as $key => $value) {
        if ($value === null) {
            unset($body[$key]);
        }
    }

    ($this->subscribe)($body)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');

    expect(NotificationEndpoint::query()->count())->toBe(0);
})->with([
    'a driver that is not push' => [['driver' => 'telegram']],
    'an unknown driver' => [['driver' => 'email']],
    'an endpoint that is not a url' => [['subscription' => ['endpoint' => 'not a url']]],
    'an endpoint that is not https' => [['subscription' => ['endpoint' => 'http://push.example.test/send/abc']]],
    'no p256dh key' => [['subscription' => ['keys' => ['p256dh' => '']]]],
    'no auth key' => [['subscription' => ['keys' => ['auth' => '']]]],
    'no subscription' => [['subscription' => null]],
]);

it('requires an Idempotency-Key and replays the same answer for the same key', function () {
    NotificationDrivers::push();
    $this->postJson('/api/v1/notifications/endpoints', ($this->subscription)())->assertStatus(400)->assertJsonPath('message_key', 'idempotency.key_required');
    $headers = idempotencyKey();

    $first = $this->withHeaders($headers)->postJson('/api/v1/notifications/endpoints', ($this->subscription)())->assertCreated();
    $second = $this->withHeaders($headers)->postJson('/api/v1/notifications/endpoints', ($this->subscription)())->assertCreated();

    expect($second->json())->toBe($first->json())
        ->and($second->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and(NotificationEndpoint::query()->count())->toBe(1);
});

it('revokes my endpoint: it stops being used and is audited, and the row is kept', function () {
    NotificationDrivers::push();
    $id = ($this->subscribe)()->assertCreated()->json('id');

    $response = $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/notifications/endpoints/{$id}")->assertOk();

    $row = NotificationEndpoint::withTrashed()->findOrFail($id);
    expect($response->json('id'))->toBe($id)->and($response->json('driver'))->toBe('push')
        ->and($row->revoked_at)->not->toBeNull()
        ->and($row->deleted_at)->not->toBeNull()
        ->and(NotificationEndpoint::query()->count())->toBe(0)
        ->and(($this->audits)('notification.endpoint.revoked'))->toBe(1)
        ->and($this->getJson('/api/v1/notifications/channels')->json('push.subscribed'))->toBeFalse();
});

it('revokes a telegram link the same way, even when push is off', function () {
    $link = NotificationEndpoint::factory()->telegram()->create(['branch_id' => $this->branch->id, 'user_id' => $this->me->id]);

    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/notifications/endpoints/{$link->id}")->assertOk();

    expect(NotificationEndpoint::query()->count())->toBe(0);
});

it('is 404 for an endpoint of someone else, of another branch, one already revoked, or that does not exist', function () {
    $theirs = NotificationEndpoint::factory()->push()->create(['branch_id' => $this->branch->id, 'user_id' => $this->colleague->id]);
    $foreign = NotificationEndpoint::factory()->push()->create(['branch_id' => $this->other->id, 'user_id' => $this->stranger->id]);
    $revoked = NotificationEndpoint::factory()->push()->create(['branch_id' => $this->branch->id, 'user_id' => $this->me->id]);
    $revoked->delete();

    foreach ([$theirs->id, $foreign->id, $revoked->id, 999999] as $id) {
        $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/notifications/endpoints/{$id}")->assertNotFound()->assertJsonPath('message_key', 'http.not_found');
    }

    expect(NotificationEndpoint::query()->withoutGlobalScopes()->whereNull('deleted_at')->count())->toBe(2)
        ->and(($this->audits)('notification.endpoint.revoked'))->toBe(0);
});

it('is 403 on all three routes for a person without notifications.view', function () {
    $nobody = makeUser($this->branch, 'waiter');
    $nobody->syncRoles([]);
    $endpoint = NotificationEndpoint::factory()->push()->create(['branch_id' => $this->branch->id, 'user_id' => $nobody->id]);
    loginAs($nobody);

    $this->getJson('/api/v1/notifications/channels')->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    ($this->subscribe)()->assertForbidden();
    $this->withHeaders(idempotencyKey())->deleteJson("/api/v1/notifications/endpoints/{$endpoint->id}")->assertForbidden();
    $this->postJson('/api/v1/notifications/telegram/link', [], idempotencyKey())->assertForbidden();

    expect($endpoint->fresh()->deleted_at)->toBeNull();
});
