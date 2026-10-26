<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Models\User;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * People (admin console): users, roles, per-user permission overrides, short-code reset (section 6 "People").
 * Permission people.manage (matrix: admin only). Every write is one AuditedAction and needs an Idempotency-Key.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = Branch::factory()->create();
    $this->admin = makeUser($this->branch, 'admin');
    $this->other = Branch::factory()->create();
    $this->stranger = makeUser($this->other, 'waiter', ['name' => 'Stranger']);
    loginAs($this->admin);
    // service calls made directly by the test (ShortCodes::set) run as the admin in the admin's branch
    actInBranch($this->branch);
    withActor($this->admin->id);
});

it('lists the branch users with roles, station assignments and whether a short code is set, never other branches', function () {
    $chef = makeUser($this->branch, 'chef', ['name' => 'Kebede']);
    $kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $this->branch->id]);
    $chef->stationAssignments()->create(['branch_id' => $this->branch->id, 'station_id' => $kitchen->id, 'can_count' => true, 'is_overseer' => false]);
    actInBranch($this->branch);
    app(ShortCodes::class)->set($chef, '1234');

    $response = $this->getJson('/api/v1/users')->assertOk();

    $ids = array_column($response->json('data'), 'id');
    expect($ids)->toContain($this->admin->id, $chef->id)->not->toContain($this->stranger->id);

    $row = collect($response->json('data'))->firstWhere('id', $chef->id);
    expect($row['roles'])->toBe(['chef'])
        ->and($row['has_short_code'])->toBeTrue()
        ->and($row['stations'])->toBe([[
            'station_id' => $kitchen->id,
            'code' => 'kitchen',
            'name_en' => $kitchen->name_en,
            'name_am' => $kitchen->name_am,
            'can_count' => true,
            'is_overseer' => false,
        ]])
        ->and($row)->not->toHaveKeys(['password', 'short_code_hash', 'short_code', 'remember_token']);
})->group('module:identity', 'feature');

it('creates a user with a role, an optional short code and permission overrides', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/users', [
        'name' => 'Sara',
        'phone' => '+251922000002',
        'email' => 'sara@example.com',
        'password' => 'long-enough-pass',
        'locale' => 'am',
        'role' => 'cashier',
        'permissions' => ['orders.void'],
        'short_code' => '5566',
        'photo_url' => 'https://example.com/sara.jpg',
    ]);

    $response->assertCreated()
        ->assertJsonPath('name', 'Sara')
        ->assertJsonPath('phone', '+251922000002')
        ->assertJsonPath('branch_id', $this->branch->id)
        ->assertJsonPath('locale', 'am')
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('roles', ['cashier'])
        ->assertJsonPath('permissions_direct', ['orders.void'])
        ->assertJsonPath('has_short_code', true)
        ->assertJsonMissingPath('short_code')
        ->assertJsonMissingPath('password')
        ->assertJsonMissingPath('short_code_hash');

    $user = User::query()->withoutGlobalScopes()->findOrFail($response->json('id'));
    expect($user->hasRole('cashier'))->toBeTrue()
        ->and($user->can('orders.void'))->toBeTrue()
        ->and($user->can('people.manage'))->toBeFalse()
        ->and(app(ShortCodes::class)->verify($this->branch->id, '5566', 'test')->id)->toBe($user->id)
        ->and(AuditLog::query()->where('action', 'user.create')->where('subject_id', $user->id)->where('actor_id', $this->admin->id)->exists())->toBeTrue();
})->group('module:identity', 'feature');

it('validates the create payload: unique phone per branch, locale, role, password length, short code format', function () {
    $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/users', [
        'name' => '',
        'phone' => $this->admin->phone,
        'password' => 'short',
        'locale' => 'fr',
        'role' => 'ceo',
        'short_code' => '12',
        'permissions' => ['not.a.permission'],
    ]);

    $response->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(array_keys($response->json('errors')))->toContain('name', 'phone', 'password', 'locale', 'role', 'short_code', 'permissions.0');

    // the same phone in another branch is fine
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/users', [
        'name' => 'Twin', 'phone' => $this->stranger->phone, 'password' => 'long-enough-pass', 'locale' => 'en', 'role' => 'waiter',
    ])->assertCreated();
})->group('module:identity', 'feature');

it('rejects a short code already used in the branch at creation', function () {
    actInBranch($this->branch);
    app(ShortCodes::class)->set($this->admin, '7777');

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/users', [
        'name' => 'Dup', 'phone' => '+251922000009', 'password' => 'long-enough-pass', 'locale' => 'en', 'role' => 'waiter', 'short_code' => '7777',
    ])->assertStatus(422)->assertJsonPath('message_key', 'identity.short_code.taken');

    expect(User::query()->where('phone', '+251922000009')->exists())->toBeFalse();
})->group('module:identity', 'feature');

it('updates name, locale, activity, role and overrides in one audited write', function () {
    $user = makeUser($this->branch, 'waiter', ['name' => 'Before']);
    $user->givePermissionTo('orders.void');

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/users/{$user->id}", [
        'name' => 'After',
        'locale' => 'am',
        'is_active' => false,
        'role' => 'cashier',
        'permissions' => ['payments.void'],
    ])->assertOk()
        ->assertJsonPath('name', 'After')
        ->assertJsonPath('locale', 'am')
        ->assertJsonPath('is_active', false)
        ->assertJsonPath('roles', ['cashier'])
        ->assertJsonPath('permissions_direct', ['payments.void']);

    $user->refresh();
    expect($user->hasRole('cashier'))->toBeTrue()
        ->and($user->hasRole('waiter'))->toBeFalse()
        ->and($user->getDirectPermissions()->pluck('name')->all())->toBe(['payments.void'])
        ->and(AuditLog::query()->where('action', 'user.update')->where('subject_id', $user->id)->count())->toBe(1);

    $row = AuditLog::query()->where('action', 'user.update')->where('subject_id', $user->id)->firstOrFail();
    expect($row->before['name'] ?? null)->toBe('Before')->and($row->after['name'] ?? null)->toBe('After');
})->group('module:identity', 'feature');

it('changes the password through PATCH and the old one stops working', function () {
    $user = makeUser($this->branch, 'waiter', ['phone' => '+251933000003']);

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/users/{$user->id}", ['password' => 'brand-new-pass'])->assertOk();

    $this->postJson('/api/v1/login', ['phone' => '+251933000003', 'password' => 'password'])->assertStatus(401);
    $this->postJson('/api/v1/login', ['phone' => '+251933000003', 'password' => 'brand-new-pass'])->assertOk();
})->group('module:identity', 'feature');

it('returns 404 for a user of another branch on every per-user route', function () {
    $id = $this->stranger->id;

    $this->withHeaders(idempotencyKey())->patchJson("/api/v1/users/{$id}", ['name' => 'x'])->assertStatus(404)->assertJsonPath('message_key', 'http.not_found');
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/users/{$id}/short-code/reset")->assertStatus(404);
    $this->withHeaders(idempotencyKey())->putJson("/api/v1/users/{$id}/stations", ['assignments' => []])->assertStatus(404);

    expect($this->stranger->fresh()?->name)->toBe('Stranger');
})->group('module:identity', 'feature');

it('resets a short code: returns the new code once, the old one stops verifying, the hash is never shown again', function () {
    $user = makeUser($this->branch, 'waiter');
    actInBranch($this->branch);
    app(ShortCodes::class)->set($user, '1111');

    $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/users/{$user->id}/short-code/reset")->assertOk();
    $code = (string) $response->json('short_code');

    expect($code)->toMatch('/^\d{4,6}$/')
        ->and($response->json('user_id'))->toBe($user->id)
        ->and(app(ShortCodes::class)->verify($this->branch->id, $code, 'test')->id)->toBe($user->id)
        ->and(fn () => app(ShortCodes::class)->verify($this->branch->id, '1111', 'test-2'))->toThrow(IdentityError::class);

    $row = AuditLog::query()->where('action', 'user.short_code.reset')->where('subject_id', $user->id)->firstOrFail();
    expect($row->after['short_code_hash'] ?? null)->toBe('[redacted]')
        ->and(json_encode($row->after))->not->toContain($code);

    $this->getJson('/api/v1/users')->assertOk()->assertJsonMissingPath('data.0.short_code');
})->group('module:identity', 'feature');

it('is forbidden for every role but admin', function () {
    foreach (array_diff(identityRoles(), ['admin']) as $role) {
        loginAs(makeUser($this->branch, $role));
        $this->getJson('/api/v1/users')->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
        $this->withHeaders(idempotencyKey())->postJson('/api/v1/users', [])->assertStatus(403);
    }
})->group('module:identity', 'feature');

it('replays a user creation idempotently', function () {
    assertIdempotent($this, 'POST', '/api/v1/users', [
        'name' => 'Replay', 'phone' => '+251944000004', 'password' => 'long-enough-pass', 'locale' => 'en', 'role' => 'waiter',
    ], 'users');
})->group('module:identity', 'feature');

// step 4.3e: no secret of the People writes is readable from idempotency_keys (tests/Modules/Shared/IdempotencySecretsTest.php has the rules)
it('keeps the password and the short code out of the stored fingerprint of a new user', function () {
    $key = (string) Str::uuid();
    $body = ['name' => 'Sara', 'phone' => '+251922000009', 'password' => 'correct-horse-9', 'locale' => 'en', 'role' => 'cashier', 'short_code' => '482913'];

    $this->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/users', $body)->assertCreated();

    $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $rest = ['locale' => 'en', 'name' => 'Sara', 'phone' => '+251922000009', 'role' => 'cashier'];
    $hash = (string) DB::table('idempotency_keys')->where('key', $key)->value('request_hash');
    expect($hash)->toBe(hash('sha256', 'POST api/v1/users '.json_encode($rest, $flags)))
        ->and(json_encode(DB::table('idempotency_keys')->get()))->not->toContain('482913')->not->toContain('correct-horse-9');
})->group('module:identity', 'feature');

it('stores the answer of a short-code reset encrypted: the new code is not in the table, and a replay hands back the same code', function () {
    $user = makeUser($this->branch, 'waiter');
    $key = (string) Str::uuid();

    $first = $this->withHeaders(['Idempotency-Key' => $key])->postJson("/api/v1/users/{$user->id}/short-code/reset")->assertOk();
    $code = (string) $first->json('short_code');
    $second = $this->withHeaders(['Idempotency-Key' => $key])->postJson("/api/v1/users/{$user->id}/short-code/reset");

    $second->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('short_code', $code);
    expect(json_encode(DB::table('idempotency_keys')->get()))->not->toContain($code)
        ->and(app(ShortCodes::class)->verify($this->branch->id, $code, 'test')->id)->toBe($user->id);
})->group('module:identity', 'feature');
