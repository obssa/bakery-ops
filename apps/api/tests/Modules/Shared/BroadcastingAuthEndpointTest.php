<?php

declare(strict_types=1);

use App\Shared\Enums\StationCode;
use App\Shared\Live\Channels;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Support\Facades\DB;

/*
 * POST /api/v1/broadcasting/auth (ADR 0009 decision 5): what Laravel Echo calls before it subscribes to a private
 * channel. Bearer token (auth:sanctum, as every API route), then the channel rules (ChannelRules, on Identity's
 * Access) decide; the configured transport only signs. With BROADCAST_CONNECTION=null (the live host) there is
 * nothing to sign and the answer says so; with reverb or pusher it is the transport's signature. It signs a
 * subscription and writes nothing, so it takes no Idempotency-Key (a read-like POST, tests/Support/read_like_writes.php).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->other = Branch::factory()->create();
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $this->bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);
    $this->theirKitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->other->id]);
    $this->chef = makeUser($this->branch, 'chef');
    $this->chef->stationAssignments()->create(['branch_id' => $this->branch->id, 'station_id' => $this->kitchen->id, 'can_count' => false, 'is_overseer' => false]);
    $this->subscribe = fn (string $channel, string $socket = '1234.5678') => $this->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => $socket,
        'channel_name' => $channel,
    ]);
});

it('answers 401 without a bearer token', function () {
    ($this->subscribe)('private-'.Channels::station($this->kitchen->id))
        ->assertStatus(401)
        ->assertJsonPath('message_key', 'auth.unauthenticated');
})->group('module:shared', 'module:identity', 'feature');

it('answers 403 for a station of another branch', function () {
    loginAs($this->chef);

    ($this->subscribe)('private-'.Channels::station($this->theirKitchen->id))
        ->assertStatus(403)
        ->assertJsonPath('message_key', 'identity.channel.forbidden')
        ->assertJsonPath('message_en', trans('identity.channel.forbidden', [], 'en'))
        ->assertJsonPath('message_am', trans('identity.channel.forbidden', [], 'am'));
})->group('module:shared', 'module:identity', 'feature');

it('answers 403 for a station they do not work at, another user, another branch, or a name it does not know', function () {
    $stranger = makeUser($this->other, 'waiter');
    loginAs($this->chef);

    foreach ([
        'private-'.Channels::station($this->bar->id),
        'private-'.Channels::user($stranger->id),
        'private-'.Channels::branchStock($this->other->id),
        'private-'.Channels::branchOrders($this->branch->id),
        'private-orders.1',
        Channels::station($this->kitchen->id),
    ] as $channel) {
        ($this->subscribe)($channel)->assertStatus(403)->assertJsonPath('message_key', 'identity.channel.forbidden');
    }
})->group('module:shared', 'module:identity', 'feature');

it('allows its own channels under the null broadcaster, with nothing to sign, no Idempotency-Key and nothing written', function () {
    loginAs($this->chef);
    $before = [DB::table('audit_log')->count(), DB::table('idempotency_keys')->count()];

    foreach ([
        'private-'.Channels::station($this->kitchen->id),
        'private-'.Channels::user($this->chef->id),
        'private-'.Channels::branchStock($this->branch->id),
    ] as $channel) {
        ($this->subscribe)($channel)
            ->assertOk()
            ->assertExactJson(['channel_name' => $channel, 'auth' => null]);
    }

    expect([DB::table('audit_log')->count(), DB::table('idempotency_keys')->count()])->toBe($before);
})->group('module:shared', 'module:identity', 'feature');

it('returns the transport signature once reverb is configured, and still refuses a foreign station', function () {
    useLiveTransport('reverb');
    loginAs($this->chef);
    $channel = 'private-'.Channels::station($this->kitchen->id);

    ($this->subscribe)($channel, '4321.8765')
        ->assertOk()
        ->assertJsonPath('channel_name', $channel)
        ->assertJsonPath('auth', 'test-key:'.hash_hmac('sha256', "4321.8765:{$channel}", 'test-secret'));

    ($this->subscribe)('private-'.Channels::station($this->theirKitchen->id))
        ->assertStatus(403)
        ->assertJsonPath('message_key', 'identity.channel.forbidden');
})->group('module:shared', 'module:identity', 'feature');

it('accepts the form-encoded body pusher-js sends', function () {
    useLiveTransport('pusher');
    loginAs($this->chef);
    $channel = 'private-'.Channels::user($this->chef->id);

    $this->post('/api/v1/broadcasting/auth', ['socket_id' => '11.22', 'channel_name' => $channel], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('auth', 'test-key:'.hash_hmac('sha256', "11.22:{$channel}", 'test-secret'));
})->group('module:shared', 'module:identity', 'feature');

it('validates the subscription request', function (array $payload) {
    loginAs($this->chef);

    $this->postJson('/api/v1/broadcasting/auth', $payload)
        ->assertStatus(422)
        ->assertJsonPath('message_key', 'validation.failed');
})->with([
    'nothing' => [[]],
    'no socket id' => [['channel_name' => 'private-user.1']],
    'malformed socket id' => [['socket_id' => '12;34', 'channel_name' => 'private-user.1']],
    'no channel name' => [['socket_id' => '1.2']],
    'channel name too long' => [['socket_id' => '1.2', 'channel_name' => 'private-'.str_repeat('a', 200)]],
])->group('module:shared', 'module:identity', 'feature');

it('refuses a switched-off account before any channel rule runs', function () {
    $this->chef->forceFill(['is_active' => false])->save();
    loginAs($this->chef->fresh() ?? $this->chef);

    ($this->subscribe)('private-'.Channels::user($this->chef->id))
        ->assertStatus(403)
        ->assertJsonPath('message_key', 'identity.user.inactive');
})->group('module:shared', 'module:identity', 'feature');
