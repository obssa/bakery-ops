<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\Device;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;

/*
 * Devices: the shared pads and tills, identified by the X-Device-Id fingerprint. Optional until Phase 5
 * (EnsureEnrolledDevice behind a flag); enrolled and revoked from People now so Phase 1 pads can be stamped.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = makeUser($this->branch, 'admin');
    $this->other = Branch::factory()->create();
    loginAs($this->admin);
});

it('lists the branch devices only', function () {
    $pad = Device::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Pad 1']);
    Device::factory()->create(['branch_id' => $this->other->id]);

    $response = $this->getJson('/api/v1/devices')->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$pad->id])
        ->and($response->json('data.0.name'))->toBe('Pad 1');
})->group('module:identity', 'feature');

it('enrols a device stamped with the admin and the time, audited', function () {
    $before = CarbonImmutable::now()->startOfSecond();

    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices', ['name' => 'Till tablet', 'fingerprint' => 'till-abc']);

    $response->assertCreated()
        ->assertJsonPath('name', 'Till tablet')
        ->assertJsonPath('fingerprint', 'till-abc')
        ->assertJsonPath('branch_id', $this->branch->id)
        ->assertJsonPath('enrolled_by', $this->admin->id)
        ->assertJsonPath('revoked_at', null);

    $device = Device::query()->findOrFail($response->json('id'));
    expect($device->enrolled_at->greaterThanOrEqualTo($before))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'device.enrol')->where('subject_id', $device->id)->exists())->toBeTrue();

    // the fingerprint now resolves on protected routes
    $this->withHeaders(['X-Device-Id' => 'till-abc'])->getJson('/api/v1/me')->assertOk()->assertJsonPath('device.id', $device->id);
})->group('module:identity', 'feature');

it('validates name and a globally unique fingerprint', function () {
    Device::factory()->create(['branch_id' => $this->other->id, 'fingerprint' => 'taken']);

    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices', ['fingerprint' => 'taken']);

    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('name', 'fingerprint');
})->group('module:identity', 'feature');

it('revokes a device, after which its header is refused; revoking twice is harmless', function () {
    $device = Device::factory()->create(['branch_id' => $this->branch->id, 'fingerprint' => 'pad-x']);

    $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$device->id}/revoke")
        ->assertOk()
        ->assertJsonPath('id', $device->id);
    $revokedAt = $device->fresh()?->revoked_at;
    expect($revokedAt)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'device.revoke')->where('subject_id', $device->id)->exists())->toBeTrue();

    $this->withHeaders(['X-Device-Id' => 'pad-x'])->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('message_key', 'identity.device.revoked');

    $this->flushHeaders();
    $this->travel(5)->minutes();
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$device->id}/revoke")->assertOk();
    expect($device->fresh()?->revoked_at?->equalTo($revokedAt))->toBeTrue();
})->group('module:identity', 'feature');

it('returns 404 for a device of another branch', function () {
    $foreign = Device::factory()->create(['branch_id' => $this->other->id]);

    $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$foreign->id}/revoke")->assertStatus(404);
    expect($foreign->fresh()?->revoked_at)->toBeNull();
})->group('module:identity', 'feature');

it('is forbidden for every role but admin', function () {
    $device = Device::factory()->create(['branch_id' => $this->branch->id]);
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/devices')->assertStatus(403);
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/devices', [])->assertStatus(403);
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/devices/{$device->id}/revoke")->assertStatus(403);
    }
})->group('module:identity', 'feature');

it('replays a device enrolment idempotently', function () {
    assertIdempotent($this, 'POST', '/api/v1/devices', ['name' => 'Pad 9', 'fingerprint' => 'pad-9'], 'devices');
})->group('module:identity', 'feature');
