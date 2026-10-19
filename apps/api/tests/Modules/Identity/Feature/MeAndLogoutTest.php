<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\ActingSession;
use App\Modules\Identity\Models\Device;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;

/*
 * GET /me is what the shell renders from after login: the user, its effective permissions, its locale, and the
 * request context the ResolveActor middleware worked out (device header, acting-as session). POST /logout revokes
 * the bearer token.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->cashier = makeUser($this->branch, 'cashier', ['locale' => 'en']);
});

it('returns the user, permissions and locale for a bearer token, with no device or acting user by default', function () {
    loginAs($this->cashier);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('user.id', $this->cashier->id)
        ->assertJsonPath('user.roles.0', 'cashier')
        ->assertJsonPath('locale', 'en')
        ->assertJsonPath('acting', null)
        ->assertJsonPath('device', null)
        ->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.short_code_hash');
})->group('module:identity', 'feature');

it('reports the enrolled device and the acting user when the headers are valid', function () {
    $device = Device::factory()->create(['branch_id' => $this->branch->id, 'fingerprint' => 'pad-7']);
    $waiter = makeUser($this->branch, 'waiter', ['name' => 'Almaz']);
    $session = ActingSession::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $waiter->id, 'device_id' => $device->id]);
    loginAs($this->cashier);

    $this->withHeaders(['X-Device-Id' => 'pad-7', 'X-Acting-Session' => (string) $session->id])
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('device.id', $device->id)
        ->assertJsonPath('acting.user.id', $waiter->id)
        ->assertJsonPath('acting.user.name', 'Almaz')
        ->assertJsonPath('acting.session.id', $session->id);
})->group('module:identity', 'feature');

it('ignores an unknown device fingerprint until Phase 5 enforces enrolment', function () {
    loginAs($this->cashier);

    $this->withHeaders(['X-Device-Id' => 'never-enrolled'])->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('device', null);
})->group('module:identity', 'feature');

it('refuses a revoked device', function () {
    Device::factory()->revoked()->create(['branch_id' => $this->branch->id, 'fingerprint' => 'pad-old']);
    loginAs($this->cashier);

    $this->withHeaders(['X-Device-Id' => 'pad-old'])->getJson('/api/v1/me')
        ->assertStatus(403)
        ->assertJsonPath('message_key', 'identity.device.revoked');
})->group('module:identity', 'feature');

it('rejects an expired or ended acting session with 401 so the pad locks again', function () {
    $waiter = makeUser($this->branch, 'waiter');
    $session = ActingSession::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $waiter->id]);
    loginAs($this->cashier);

    $this->withHeaders(['X-Acting-Session' => (string) $session->id])->getJson('/api/v1/me')->assertOk();

    $this->travel(10)->minutes();
    $this->travel(1)->seconds();
    $this->withHeaders(['X-Acting-Session' => (string) $session->id])->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('message_key', 'identity.acting_session.expired');

    $this->travelBack();
    $session->forceFill(['ended_at' => now()])->save();
    $this->withHeaders(['X-Acting-Session' => (string) $session->id])->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('message_key', 'identity.acting_session.expired');
})->group('module:identity', 'feature');

it('refuses an acting session that belongs to another branch', function () {
    $other = Branch::factory()->create();
    $stranger = makeUser($other, 'waiter');
    $session = ActingSession::factory()->create(['branch_id' => $other->id, 'user_id' => $stranger->id]);
    loginAs($this->cashier);

    $this->withHeaders(['X-Acting-Session' => (string) $session->id])->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('message_key', 'identity.acting_session.expired');
})->group('module:identity', 'feature');

it('refuses without a token and refuses an inactive user with a token', function () {
    $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('message_key', 'auth.unauthenticated');

    $this->cashier->forceFill(['is_active' => false])->save();
    loginAs($this->cashier);
    $this->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('message_key', 'identity.user.inactive');
})->group('module:identity', 'feature');

it('revokes the current token on logout and records it', function () {
    $token = (string) $this->postJson('/api/v1/login', ['phone' => $this->cashier->phone, 'password' => 'password'])->json('token');
    expect(DB::table('personal_access_tokens')->count())->toBe(1);

    $this->withToken($token)->withHeaders(idempotencyKey())->postJson('/api/v1/logout')
        ->assertOk()
        ->assertJsonPath('logged_out', true);

    expect(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'auth.logout')->where('actor_id', $this->cashier->id)->exists())->toBeTrue();

    // every real request builds a fresh guard; the test kernel reuses one, so forget the cached user first
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
})->group('module:identity', 'feature');

it('requires an Idempotency-Key on logout like every write', function () {
    loginAs($this->cashier);

    $this->postJson('/api/v1/logout')->assertStatus(400)->assertJsonPath('message_key', 'idempotency.key_required');
})->group('module:identity', 'feature');
