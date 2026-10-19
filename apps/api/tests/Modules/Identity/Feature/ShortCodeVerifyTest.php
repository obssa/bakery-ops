<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\ActingSession;
use App\Modules\Identity\Models\Device;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;

/*
 * manifest identity.provides: "verifyShortCode(branch, code) -> User; argon2id; 5 attempts then 15-min lockout" and
 * "ActingAsSession::start(user, 10 min)". The shared pad is not logged in as anyone: it sends its X-Device-Id and the
 * code; the branch comes from the enrolled device (or branch_id until Phase 5 enforces enrolment). A code identifies
 * nobody until it matches, so failed attempts are counted per entry point (branch + device); a pad that names the
 * user (user_id) counts them on that user (short_code_attempts / pin_locked_until).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->device = Device::factory()->create(['branch_id' => $this->branch->id, 'fingerprint' => 'pad-1']);
    $this->waiter = makeUser($this->branch, 'waiter', ['name' => 'Almaz']);
    // the admin's write of record; the request-scoped context is cleared again so the public route starts cold
    actInBranch($this->branch);
    withActor(makeUser($this->branch, 'admin')->id);
    app(ShortCodes::class)->set($this->waiter, '4821');
    app(CurrentBranch::class)->clear();
    app(ActorContext::class)->reset();
});

it('starts a 10-minute acting session for the right code and returns the actor', function () {
    $before = CarbonImmutable::now()->startOfSecond();

    $response = $this->withHeaders(['X-Device-Id' => 'pad-1'])->postJson('/api/v1/short-code/verify', ['code' => '4821']);

    $response->assertOk()
        ->assertJsonPath('user.id', $this->waiter->id)
        ->assertJsonPath('user.name', 'Almaz')
        ->assertJsonMissingPath('user.short_code_hash')
        ->assertJsonMissingPath('user.password');
    expect($response->json('permissions'))->toContain('orders.create')
        ->and($response->json('acting_session.id'))->toBeInt();

    $session = ActingSession::query()->withoutGlobalScopes()->findOrFail($response->json('acting_session.id'));
    expect($session->user_id)->toBe($this->waiter->id)
        ->and($session->device_id)->toBe($this->device->id)
        ->and($session->branch_id)->toBe($this->branch->id)
        ->and($session->ended_at)->toBeNull()
        ->and($session->expires_at->greaterThanOrEqualTo($before->addMinutes(10)))->toBeTrue()
        ->and($session->expires_at->lessThanOrEqualTo(CarbonImmutable::now()->addMinutes(10)->addSecond()))->toBeTrue()
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'short_code.verified')->where('actor_id', $this->waiter->id)->where('device_id', $this->device->id)->exists())->toBeTrue();
})->group('module:identity', 'feature');

it('requires the X-Device-Id header', function () {
    $this->postJson('/api/v1/short-code/verify', ['code' => '4821'])
        ->assertStatus(400)
        ->assertJsonPath('message_key', 'identity.device.required');
})->group('module:identity', 'feature');

it('needs branch_id in the body when the device is not enrolled yet', function () {
    $this->withHeaders(['X-Device-Id' => 'fresh-tablet'])->postJson('/api/v1/short-code/verify', ['code' => '4821'])
        ->assertStatus(422)
        ->assertJsonPath('message_key', 'identity.branch.required');

    $this->withHeaders(['X-Device-Id' => 'fresh-tablet'])->postJson('/api/v1/short-code/verify', ['code' => '4821', 'branch_id' => $this->branch->id])
        ->assertOk()
        ->assertJsonPath('user.id', $this->waiter->id);

    expect(ActingSession::query()->withoutGlobalScopes()->latest('id')->firstOrFail()->device_id)->toBeNull();
})->group('module:identity', 'feature');

it('refuses a revoked device', function () {
    Device::factory()->revoked()->create(['branch_id' => $this->branch->id, 'fingerprint' => 'pad-gone']);

    $this->withHeaders(['X-Device-Id' => 'pad-gone'])->postJson('/api/v1/short-code/verify', ['code' => '4821'])
        ->assertStatus(403)
        ->assertJsonPath('message_key', 'identity.device.revoked');
})->group('module:identity', 'feature');

it('rejects a wrong code without revealing anything and starts no session', function () {
    $this->withHeaders(['X-Device-Id' => 'pad-1'])->postJson('/api/v1/short-code/verify', ['code' => '0000'])
        ->assertStatus(401)
        ->assertJsonPath('message_key', 'identity.short_code.invalid')
        ->assertJsonStructure(['message_key', 'message_en', 'message_am']);

    expect(ActingSession::query()->withoutGlobalScopes()->count())->toBe(0);
})->group('module:identity', 'feature');

it('validates the code format', function () {
    $this->withHeaders(['X-Device-Id' => 'pad-1'])->postJson('/api/v1/short-code/verify', ['code' => 'abcd'])
        ->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->withHeaders(['X-Device-Id' => 'pad-1'])->postJson('/api/v1/short-code/verify', [])
        ->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
})->group('module:identity', 'feature');

it('locks the entry point after 5 failed attempts and unlocks after 15 minutes', function () {
    foreach (range(1, 5) as $i) {
        $this->withHeaders(['X-Device-Id' => 'pad-1'])->postJson('/api/v1/short-code/verify', ['code' => '0000'])->assertStatus(401);
    }

    // the sixth attempt is refused even with the right code
    $locked = $this->withHeaders(['X-Device-Id' => 'pad-1'])->postJson('/api/v1/short-code/verify', ['code' => '4821']);
    $locked->assertStatus(423)->assertJsonPath('message_key', 'identity.short_code.locked');
    expect($locked->json('retry_after_seconds'))->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(15 * 60);

    // another pad in the same branch is not locked by this one
    $this->withHeaders(['X-Device-Id' => 'other-pad'])->postJson('/api/v1/short-code/verify', ['code' => '4821', 'branch_id' => $this->branch->id])->assertOk();

    $this->travel(15)->minutes();
    $this->travel(1)->seconds();
    $this->withHeaders(['X-Device-Id' => 'pad-1'])->postJson('/api/v1/short-code/verify', ['code' => '4821'])
        ->assertOk()
        ->assertJsonPath('user.id', $this->waiter->id);
})->group('module:identity', 'feature');

it('locks a named user after 5 failed attempts against that user and resets on success', function () {
    foreach (range(1, 5) as $i) {
        $this->withHeaders(['X-Device-Id' => 'pad-1'])->postJson('/api/v1/short-code/verify', ['code' => '0000', 'user_id' => $this->waiter->id])->assertStatus(401);
    }
    $this->waiter->refresh();
    expect($this->waiter->short_code_attempts)->toBe(5)
        ->and($this->waiter->pin_locked_until)->not->toBeNull()
        ->and($this->waiter->pin_locked_until->greaterThan(CarbonImmutable::now()->addMinutes(14)))->toBeTrue();

    $this->withHeaders(['X-Device-Id' => 'pad-2'])->postJson('/api/v1/short-code/verify', ['code' => '4821', 'user_id' => $this->waiter->id, 'branch_id' => $this->branch->id])
        ->assertStatus(423)
        ->assertJsonPath('message_key', 'identity.short_code.locked');

    $this->travel(16)->minutes();
    $this->withHeaders(['X-Device-Id' => 'pad-2'])->postJson('/api/v1/short-code/verify', ['code' => '4821', 'user_id' => $this->waiter->id, 'branch_id' => $this->branch->id])
        ->assertOk()
        ->assertJsonPath('user.id', $this->waiter->id);

    $this->waiter->refresh();
    expect($this->waiter->short_code_attempts)->toBe(0)
        ->and($this->waiter->pin_locked_until)->toBeNull();
})->group('module:identity', 'feature');

it('does not accept a code from another branch, nor from an inactive user', function () {
    $other = Branch::factory()->create();
    $stranger = makeUser($other, 'waiter');
    actInBranch($other);
    withActor($stranger->id);
    app(ShortCodes::class)->set($stranger, '9999');
    app(CurrentBranch::class)->clear();
    app(ActorContext::class)->reset();

    $this->withHeaders(['X-Device-Id' => 'pad-1'])->postJson('/api/v1/short-code/verify', ['code' => '9999'])
        ->assertStatus(401)->assertJsonPath('message_key', 'identity.short_code.invalid');

    $this->waiter->forceFill(['is_active' => false])->save();
    $this->withHeaders(['X-Device-Id' => 'pad-1'])->postJson('/api/v1/short-code/verify', ['code' => '4821'])
        ->assertStatus(401)->assertJsonPath('message_key', 'identity.short_code.invalid');
})->group('module:identity', 'feature');

it('attributes the request to the logged-in pad account when a bearer token is also sent', function () {
    $padAccount = makeUser($this->branch, 'cashier');
    loginAs($padAccount);

    $response = $this->withHeaders(['X-Device-Id' => 'pad-1'])->postJson('/api/v1/short-code/verify', ['code' => '4821']);
    $response->assertOk()->assertJsonPath('user.id', $this->waiter->id);

    $row = AuditLog::query()->withoutGlobalScopes()->where('action', 'short_code.verified')->firstOrFail();
    expect($row->actor_id)->toBe($padAccount->id)
        ->and($row->acting_user_id)->toBe($this->waiter->id);
})->group('module:identity', 'feature');
