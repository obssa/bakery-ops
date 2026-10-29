<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Middleware\ResolveActor;
use App\Modules\Identity\Models\Device;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;

pest()->group('module:identity', 'feature');

/*
 * Step 5.1a (phase-5 plan 4.1, ADR 0017): EnsureEnrolledDevice, the `device` middleware on every station route. Behind
 * identity.require_enrolled_device (env IDENTITY_REQUIRE_DEVICE), read from the config on every request; unset means off.
 * Off: today's behaviour in every case (no header, an unknown fingerprint and another branch's device pass; a revoked device of the
 * caller's branch is refused by the actor middleware, as since Phase 0). On: a missing or unknown X-Device-Id is
 * 403 identity.device.unknown, a revoked one 403 identity.device.revoked, another branch's 403 identity.device.other_branch, each
 * bilingual; an enrolled device of the caller's branch passes. GET /dining-tables (the pad's table grid) stands for a station
 * route, GET /me for the rest.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->waiter = makeUser($this->branch, 'waiter');
    $this->pad = Device::factory()->create(['branch_id' => $this->branch->id, 'fingerprint' => 'pad-enrolled']);
    $this->revoked = Device::factory()->revoked()->create(['branch_id' => $this->branch->id, 'fingerprint' => 'pad-revoked']);
    $this->foreign = Device::factory()->create(['branch_id' => Branch::factory()->create()->id, 'fingerprint' => 'pad-other-branch']);
    loginAs($this->waiter);
});

/** GET /dining-tables (a station route) with these headers. */
function enrolmentStationCall(mixed $test, array $headers = []): TestResponse
{
    return $test->withHeaders($headers)->getJson('/api/v1/dining-tables');
}

function enrolmentDeviceHeader(string $fingerprint): array
{
    return [ResolveActor::DEVICE_HEADER => $fingerprint];
}

it('is off when the variable is not set, and reads the variable', function () {
    expect(config('identity.require_enrolled_device'))->toBeFalse();
    expect((string) file_get_contents(config_path('identity.php')))->toContain("env('IDENTITY_REQUIRE_DEVICE', false)");
});

it('changes nothing while the flag is off', function () {
    expect(config('identity.require_enrolled_device'))->toBeFalse();

    enrolmentStationCall($this)->assertOk();
    enrolmentStationCall($this, enrolmentDeviceHeader('never-enrolled'))->assertOk();
    enrolmentStationCall($this, enrolmentDeviceHeader('pad-other-branch'))->assertOk();
    enrolmentStationCall($this, enrolmentDeviceHeader('pad-enrolled'))->assertOk();
    // as since Phase 0: the actor middleware refuses a revoked device of the caller's branch
    enrolmentStationCall($this, enrolmentDeviceHeader('pad-revoked'))->assertStatus(403)->assertJsonPath('message_key', 'identity.device.revoked');
});

it('refuses a missing or unknown device with the flag on', function (?string $fingerprint) {
    config(['identity.require_enrolled_device' => true]);

    enrolmentStationCall($this, $fingerprint === null ? [] : enrolmentDeviceHeader($fingerprint))
        ->assertStatus(403)
        ->assertJsonPath('message_key', 'identity.device.unknown')
        ->assertJsonPath('message_en', Lang::get('identity.device.unknown', [], 'en'))
        ->assertJsonPath('message_am', Lang::get('identity.device.unknown', [], 'am'));
})->with([
    'no header' => [null],
    'an empty header' => ['  '],
    'a fingerprint nobody enrolled' => ['never-enrolled'],
]);

it('refuses a revoked device with the flag on', function () {
    config(['identity.require_enrolled_device' => true]);

    enrolmentStationCall($this, enrolmentDeviceHeader('pad-revoked'))->assertStatus(403)
        ->assertJsonPath('message_key', 'identity.device.revoked')
        ->assertJsonPath('message_am', Lang::get('identity.device.revoked', [], 'am'));
});

it('refuses another branch\'s device with the flag on', function () {
    config(['identity.require_enrolled_device' => true]);

    enrolmentStationCall($this, enrolmentDeviceHeader('pad-other-branch'))->assertStatus(403)
        ->assertJsonPath('message_key', 'identity.device.other_branch')
        ->assertJsonPath('message_en', Lang::get('identity.device.other_branch', [], 'en'))
        ->assertJsonPath('message_am', Lang::get('identity.device.other_branch', [], 'am'));
});

it('refuses a device that was deleted, as unknown', function () {
    config(['identity.require_enrolled_device' => true]);
    $this->pad->delete();

    enrolmentStationCall($this, enrolmentDeviceHeader('pad-enrolled'))->assertStatus(403)->assertJsonPath('message_key', 'identity.device.unknown');
});

it('lets an enrolled device of the caller\'s branch through with the flag on', function () {
    config(['identity.require_enrolled_device' => true]);

    enrolmentStationCall($this, enrolmentDeviceHeader('pad-enrolled'))->assertOk();
    enrolmentStationCall($this, enrolmentDeviceHeader('  pad-enrolled  '))->assertOk();
});

it('answers the device check before the permission check', function () {
    config(['identity.require_enrolled_device' => true]);
    loginAs(makeUser($this->branch, 'chef')); // no orders.create

    enrolmentStationCall($this, enrolmentDeviceHeader('never-enrolled'))->assertStatus(403)->assertJsonPath('message_key', 'identity.device.unknown');
    enrolmentStationCall($this, enrolmentDeviceHeader('pad-enrolled'))->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
});

it('leaves a route that is not a station route alone with the flag on', function () {
    config(['identity.require_enrolled_device' => true]);

    $this->getJson('/api/v1/me')->assertOk();
    $this->withHeaders(enrolmentDeviceHeader('never-enrolled'))->getJson('/api/v1/me')->assertOk();
    $this->withHeaders(enrolmentDeviceHeader('pad-other-branch'))->getJson('/api/v1/me')->assertOk();
});

it('guards the pad lock screen too, where nobody is signed in yet', function () {
    config(['identity.require_enrolled_device' => true]);
    $this->flushHeaders();

    $this->withHeaders(enrolmentDeviceHeader('never-enrolled'))->postJson('/api/v1/short-code/verify', ['code' => '1234'])
        ->assertStatus(403)->assertJsonPath('message_key', 'identity.device.unknown');
    $this->postJson('/api/v1/short-code/verify', ['code' => '1234'])
        ->assertStatus(403)->assertJsonPath('message_key', 'identity.device.unknown');
    // an enrolled pad gets past the device check (the code itself is the short-code gate's question)
    $answer = $this->withHeaders(enrolmentDeviceHeader('pad-enrolled'))->postJson('/api/v1/short-code/verify', ['code' => '1234']);
    expect((string) $answer->json('message_key'))->not->toStartWith('identity.device.');
});

it('reads the flag on every request, so the kill switch needs no restart', function () {
    config(['identity.require_enrolled_device' => true]);
    enrolmentStationCall($this, enrolmentDeviceHeader('never-enrolled'))->assertStatus(403);

    config(['identity.require_enrolled_device' => false]);
    enrolmentStationCall($this, enrolmentDeviceHeader('never-enrolled'))->assertOk();
});
