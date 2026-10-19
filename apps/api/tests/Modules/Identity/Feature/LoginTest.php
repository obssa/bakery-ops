<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;

/*
 * manifest identity.provides: "authenticate(phone, password) -> bearer token". Phone is the login id (unique per
 * branch); the answer carries the user, the effective permissions and the locale the shell renders in. Tokens are
 * Sanctum personal access tokens, never SPA cookies (ADR 0007).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->cashier = makeUser($this->branch, 'cashier', ['phone' => '+251911000001', 'password' => 'secret-pass', 'locale' => 'am']);
});

it('issues a bearer token with the user, permissions and locale for a valid phone and password', function () {
    $response = $this->postJson('/api/v1/login', ['phone' => '+251911000001', 'password' => 'secret-pass']);

    $response->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $this->cashier->id)
        ->assertJsonPath('user.phone', '+251911000001')
        ->assertJsonPath('locale', 'am')
        ->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.short_code_hash');

    $permissions = $response->json('permissions');
    expect($permissions)->toContain('orders.create', 'payments.take')->not->toContain('people.manage')
        ->and($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and(DB::table('personal_access_tokens')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'auth.login')->where('actor_id', $this->cashier->id)->exists())->toBeTrue();

    // the token works as a bearer credential on a protected route
    $this->withToken((string) $response->json('token'))->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('user.id', $this->cashier->id);
})->group('module:identity', 'feature');

it('rejects a wrong password with a bilingual 401 and issues no token', function () {
    $this->postJson('/api/v1/login', ['phone' => '+251911000001', 'password' => 'nope'])
        ->assertStatus(401)
        ->assertJsonPath('message_key', 'identity.login.invalid')
        ->assertJsonStructure(['message_key', 'message_en', 'message_am']);

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
})->group('module:identity', 'feature');

it('rejects an unknown phone and an inactive user with the same 401, revealing nothing', function () {
    $this->cashier->forceFill(['is_active' => false])->save();

    $this->postJson('/api/v1/login', ['phone' => '+251911000001', 'password' => 'secret-pass'])
        ->assertStatus(401)->assertJsonPath('message_key', 'identity.login.invalid');
    $this->postJson('/api/v1/login', ['phone' => '+251911999999', 'password' => 'secret-pass'])
        ->assertStatus(401)->assertJsonPath('message_key', 'identity.login.invalid');
})->group('module:identity', 'feature');

it('validates that phone and password are present, bilingually', function () {
    $this->postJson('/api/v1/login', [])
        ->assertStatus(422)
        ->assertJsonPath('message_key', 'validation.failed')
        ->assertJsonStructure(['message_en', 'message_am', 'errors' => ['phone', 'password']]);
})->group('module:identity', 'feature');

it('needs branch_id when the same phone exists in two branches', function () {
    $other = Branch::factory()->create();
    $twin = makeUser($other, 'waiter', ['phone' => '+251911000001', 'password' => 'secret-pass']);

    $this->postJson('/api/v1/login', ['phone' => '+251911000001', 'password' => 'secret-pass'])
        ->assertStatus(422)
        ->assertJsonPath('message_key', 'identity.branch.required');

    $this->postJson('/api/v1/login', ['phone' => '+251911000001', 'password' => 'secret-pass', 'branch_id' => $other->id])
        ->assertOk()
        ->assertJsonPath('user.id', $twin->id)
        ->assertJsonPath('user.branch_id', $other->id);
})->group('module:identity', 'feature');

it('is rate limited per client', function () {
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/login', ['phone' => '+251911000001', 'password' => 'nope'])->assertStatus(401);
    }

    $this->postJson('/api/v1/login', ['phone' => '+251911000001', 'password' => 'secret-pass'])
        ->assertStatus(429)
        ->assertJsonPath('message_key', 'http.too_many_requests');
})->group('module:identity', 'feature');
