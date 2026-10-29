<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Http\Middleware\ResolveActor;
use App\Modules\Identity\Models\Device;
use App\Shared\Enums\StationCode;
use App\Shared\Events\DeviceEnrolled;
use App\Shared\Events\DeviceRevoked;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

pest()->group('module:identity', 'feature');

/*
 * Step 5.1a (phase-5 plan 4.1, ADR 0017): the device endpoints of Phase 5. The three routes of Phase 0 move from people.manage to
 * devices.manage (the admin holds both, so nobody loses access); POST /devices takes a kind (tablet, till, kds, bridge), an optional
 * home station and a client_uuid, and a bridge gets a token once, stored as its sha256; POST /devices/{id}/rename renames and moves a
 * device; GET /devices/this tells any signed-in role whether the tablet it runs on is enrolled, and never answers 403 for an unknown or
 * revoked device: it is how the "not enrolled" screen knows. Enrol and revoke announce DeviceEnrolled and DeviceRevoked.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = makeUser($this->branch, 'admin');
    $this->other = Branch::factory()->create();
    loginAs($this->admin);
});

/** @return array<string, mixed> a valid POST /devices body */
function enrolBody(array $overrides = []): array
{
    return $overrides + ['name' => 'Pad 1', 'fingerprint' => 'fp-'.Str::uuid(), 'client_uuid' => (string) Str::uuid()];
}

it('enrols a tablet by default, with its kind, home station and client_uuid, and announces it', function () {
    Event::fake([DeviceEnrolled::class]);
    $kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $body = enrolBody(['station_id' => $kitchen->id]);

    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices', $body)->assertCreated()
        ->assertJsonPath('kind', 'tablet')
        ->assertJsonPath('station_id', $kitchen->id)
        ->assertJsonPath('last_seen_at', null)
        ->assertJsonPath('revoked_by', null)
        ->assertJsonMissingPath('token')
        ->assertJsonMissingPath('token_hash');

    $device = Device::query()->findOrFail($response->json('id'));
    expect($device->client_uuid)->toBe($body['client_uuid'])->and($device->token_hash)->toBeNull();
    Event::assertDispatched(DeviceEnrolled::class, fn (DeviceEnrolled $e): bool => $e->branchId === $this->branch->id
        && $e->payload() === ['device_id' => $device->id, 'name' => 'Pad 1', 'kind' => 'tablet', 'station_id' => $kitchen->id, 'by_user_id' => $this->admin->id]);
});

it('enrols a till and a KDS screen by kind', function (string $kind) {
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices', enrolBody(['kind' => $kind]))->assertCreated()
        ->assertJsonPath('kind', $kind)->assertJsonMissingPath('token');
})->with(['till', 'kds', 'tablet']);

it('gives a bridge its token once and keeps only the sha256', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices', enrolBody(['kind' => 'bridge', 'name' => 'Print bridge']))
        ->assertCreated()->assertJsonPath('kind', 'bridge');
    $token = (string) $response->json('token');

    expect($token)->toMatch('/^[0-9a-f]{64}$/');
    $device = Device::query()->findOrFail($response->json('id'));
    expect($device->token_hash)->toBe(hash('sha256', $token));

    $list = $this->getJson('/api/v1/devices')->assertOk();
    expect($list->getContent())->not->toContain($token)->not->toContain((string) $device->token_hash)
        ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($token)->not->toContain((string) $device->token_hash);
});

it('replays a bridge enrolment with the same token, never stored in clear', function () {
    $key = idempotencyKey();
    $body = enrolBody(['kind' => 'bridge']);

    $first = $this->withHeaders($key)->postJson('/api/v1/devices', $body)->assertCreated();
    $second = $this->withHeaders($key)->postJson('/api/v1/devices', $body)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

    expect($second->json('token'))->toBe($first->json('token'))
        ->and(Device::query()->where('kind', 'bridge')->count())->toBe(1)
        ->and(json_encode(DB::table('idempotency_keys')->get()->all()))->not->toContain((string) $first->json('token'));
});

it('answers the same device for a client_uuid already used, without a token', function () {
    $body = enrolBody(['kind' => 'bridge']);
    $first = $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices', $body)->assertCreated();

    $again = $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices', $body)->assertOk()
        ->assertJsonPath('id', $first->json('id'))->assertJsonMissingPath('token');

    expect(Device::query()->count())->toBe(1)->and($again->json('kind'))->toBe('bridge');
});

it('refuses a fingerprint already enrolled anywhere, even deleted, with its own key', function (string $where) {
    $taken = Device::factory()->create(['branch_id' => $where === 'here' ? $this->branch->id : $this->other->id, 'fingerprint' => 'taken-fp']);
    if ($where === 'deleted') {
        $taken->delete();
    }

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices', enrolBody(['fingerprint' => 'taken-fp']))
        ->assertStatus(422)->assertJsonPath('message_key', 'identity.device.fingerprint_taken');
})->with(['here', 'another branch', 'deleted']);

it('refuses an unknown kind with its own key', function () {
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices', enrolBody(['kind' => 'toaster']))
        ->assertStatus(422)->assertJsonPath('message_key', 'identity.device.kind_invalid');
    expect(Device::query()->count())->toBe(0);
});

it('validates the name, the client_uuid and a home station of the branch', function () {
    $foreign = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->other->id]);

    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices', ['fingerprint' => 'fp-x', 'client_uuid' => 'nope', 'station_id' => $foreign->id]);

    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('name', 'client_uuid', 'station_id');
});

it('renames a device and moves it to another home station, audited', function () {
    $bar = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $this->branch->id]);
    $device = Device::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Old name']);

    $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$device->id}/rename", ['name' => 'Bar tablet', 'station_id' => $bar->id])
        ->assertOk()->assertJsonPath('name', 'Bar tablet')->assertJsonPath('station_id', $bar->id);
    expect(AuditLog::query()->where('action', 'device.rename')->where('subject_id', $device->id)->exists())->toBeTrue();

    // the home station is cleared with null and left alone when it is not sent
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$device->id}/rename", ['name' => 'Spare tablet'])
        ->assertOk()->assertJsonPath('station_id', $bar->id);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$device->id}/rename", ['name' => 'Spare tablet', 'station_id' => null])
        ->assertOk()->assertJsonPath('station_id', null);
});

it('refuses to rename a revoked device, and another branch\'s device is not found', function () {
    $revoked = Device::factory()->revoked()->create(['branch_id' => $this->branch->id]);
    $foreign = Device::factory()->create(['branch_id' => $this->other->id]);

    $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$revoked->id}/rename", ['name' => 'Back again'])
        ->assertStatus(409)->assertJsonPath('message_key', 'identity.device.revoked');
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$foreign->id}/rename", ['name' => 'Mine now'])->assertStatus(404);
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices/999999/rename', ['name' => 'Nobody'])->assertStatus(404);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$foreign->id}/rename", [])->assertStatus(404);
});

it('validates a rename', function () {
    $device = Device::factory()->create(['branch_id' => $this->branch->id]);

    $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$device->id}/rename", ['name' => str_repeat('x', 121)]);

    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('name');
});

it('replays a rename idempotently', function () {
    $device = Device::factory()->create(['branch_id' => $this->branch->id]);

    assertIdempotent($this, 'POST', "/api/v1/devices/{$device->id}/rename", ['name' => 'Renamed once'], 'audit_log');
});

it('records who revoked a device and announces it once', function () {
    Event::fake([DeviceRevoked::class]);
    $device = Device::factory()->create(['branch_id' => $this->branch->id, 'kind' => 'kds']);

    $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$device->id}/revoke")->assertOk()
        ->assertJsonPath('revoked_by', $this->admin->id);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$device->id}/revoke")->assertOk();

    Event::assertDispatchedTimes(DeviceRevoked::class, 1);
    Event::assertDispatched(DeviceRevoked::class, fn (DeviceRevoked $e): bool => $e->payload() === [
        'device_id' => $device->id, 'name' => $device->name, 'kind' => 'kds', 'by_user_id' => $this->admin->id,
    ]);
});

it('lists the devices with kind, home station and last seen, filtered by kind and by revoked', function () {
    $till = Device::factory()->create(['branch_id' => $this->branch->id, 'kind' => 'till', 'name' => 'A till']);
    $pad = Device::factory()->create(['branch_id' => $this->branch->id, 'name' => 'B pad']);
    $gone = Device::factory()->revoked()->create(['branch_id' => $this->branch->id, 'name' => 'C pad']);

    $all = $this->getJson('/api/v1/devices')->assertOk();
    expect(array_column($all->json('data'), 'id'))->toBe([$till->id, $pad->id, $gone->id])
        ->and(array_keys($all->json('data.0')))->toContain('kind', 'station_id', 'last_seen_at', 'revoked_at', 'revoked_by', 'enrolled_at', 'enrolled_by');

    expect(array_column($this->getJson('/api/v1/devices?kind=till')->assertOk()->json('data'), 'id'))->toBe([$till->id])
        ->and(array_column($this->getJson('/api/v1/devices?revoked=1')->assertOk()->json('data'), 'id'))->toBe([$gone->id])
        ->and(array_column($this->getJson('/api/v1/devices?revoked=0')->assertOk()->json('data'), 'id'))->toBe([$till->id, $pad->id]);
    $this->getJson('/api/v1/devices?kind=toaster')->assertStatus(422);
});

it('tells any signed-in role whether this tablet is enrolled, and never answers 403 for it', function (string $role) {
    $mine = Device::factory()->create(['branch_id' => $this->branch->id, 'fingerprint' => 'this-pad', 'name' => 'Front pad', 'kind' => 'tablet']);
    Device::factory()->revoked()->create(['branch_id' => $this->branch->id, 'fingerprint' => 'this-gone', 'name' => 'Old pad']);
    Device::factory()->create(['branch_id' => $this->other->id, 'fingerprint' => 'that-pad']);
    loginAs(makeUser($this->branch, $role));
    $ask = fn (?string $fp) => $this->withHeaders($fp === null ? [] : [ResolveActor::DEVICE_HEADER => $fp])->getJson('/api/v1/devices/this');

    foreach ([false, true] as $flag) {
        config(['identity.require_enrolled_device' => $flag]);

        $ask('this-pad')->assertOk()->assertExactJson([
            'enrolled' => true, 'revoked' => false, 'device' => ['id' => $mine->id, 'name' => 'Front pad', 'kind' => 'tablet'], 'enforcement' => $flag,
        ]);
        $ask('never-enrolled')->assertOk()->assertExactJson(['enrolled' => false, 'revoked' => false, 'device' => null, 'enforcement' => $flag]);
        $ask(null)->assertOk()->assertExactJson(['enrolled' => false, 'revoked' => false, 'device' => null, 'enforcement' => $flag]);
        $ask('that-pad')->assertOk()->assertExactJson(['enrolled' => false, 'revoked' => false, 'device' => null, 'enforcement' => $flag]);
        $ask('this-gone')->assertOk()->assertJsonPath('enrolled', false)->assertJsonPath('revoked', true)
            ->assertJsonPath('device.name', 'Old pad')->assertJsonPath('enforcement', $flag);
    }
})->with(['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker']);

it('keeps the device routes for the admin alone, under devices.manage', function () {
    $device = Device::factory()->create(['branch_id' => $this->branch->id]);
    expect($this->admin->can('devices.manage'))->toBeTrue();

    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        $user = makeUser($this->branch, $role);
        expect($user->can('devices.manage'))->toBeFalse($role)->and($user->can('devices.self'))->toBeTrue($role);
        loginAs($user);
        $this->getJson('/api/v1/devices')->assertStatus(403);
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices', enrolBody())->assertStatus(403);
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$device->id}/rename", ['name' => 'x'])->assertStatus(403);
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$device->id}/revoke")->assertStatus(403);
    }
});
