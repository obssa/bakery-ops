<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Demo\DemoLogins;
use App\Shared\Models\Branch;
use Database\Seeders\Demo\DemoBakery;
use Illuminate\Support\Facades\DB;

/*
 * TEMPORARY (ADR 0025, Ramin 2026-11-12): the sign-in page's one-click demo buttons. Off by default and then invisible (404); on, only
 * the listed demo people of the demo branch can be signed in, with the same answer and audit row as POST /login.
 */

beforeEach(function () {
    seedRoles();
    $this->demo = Branch::factory()->create(['name' => 'Demo Bakery', 'settings' => ['demo' => true]]);
    $this->people = [];
    foreach (DemoLogins::PEOPLE as $role => $phone) {
        $this->people[$role] = makeUser($this->demo, $role, ['phone' => $phone, 'name' => "Person {$role} (ሰው {$role})", 'locale' => 'am']);
    }
});

it('answers 404 on both routes while the flag is off, and signs nobody in', function () {
    config(['bakery.demo.login' => false]);

    $this->getJson('/api/v1/demo-logins')->assertNotFound();
    $this->postJson('/api/v1/demo-login', ['role' => 'admin'])->assertNotFound();

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
})->group('module:identity', 'feature');

it('lists one demo person per role, in order, with both names and no phone or code', function () {
    config(['bakery.demo.login' => true]);

    $response = $this->getJson('/api/v1/demo-logins')->assertOk();

    expect(array_column($response->json('data'), 'role'))->toBe(array_keys(DemoLogins::PEOPLE))
        ->and($response->json('data.0'))->toBe(['role' => 'admin', 'name_en' => 'Person admin', 'name_am' => 'ሰው admin'])
        ->and($response->getContent())->not->toContain('+2519')->not->toContain('short_code');
})->group('module:identity', 'feature');

it('signs each role in with POST /login\'s answer and an auth.login audit row marked via=demo_button', function () {
    config(['bakery.demo.login' => true]);

    foreach ($this->people as $role => $user) {
        $response = $this->postJson('/api/v1/demo-login', ['role' => $role]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('locale', 'am')
            ->assertJsonMissingPath('user.password')
            ->assertJsonStructure(['token', 'token_type', 'user', 'permissions', 'locale']);

        $row = AuditLog::query()->where('action', 'auth.login')->where('actor_id', $user->id)->latest('id')->first();
        expect($row)->not->toBeNull()
            ->and(json_encode($row?->getAttributes()))->toContain('demo_button');

        $this->withToken((string) $response->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $user->id);
        app('auth')->forgetGuards();
    }
})->group('module:identity', 'feature');

it('refuses an unknown role, a switched-off account and an account no longer in its role with a bilingual 422', function () {
    config(['bakery.demo.login' => true]);

    $this->postJson('/api/v1/demo-login', ['role' => 'owner'])
        ->assertStatus(422)
        ->assertJsonPath('message_key', 'identity.demo_login.unavailable')
        ->assertJsonStructure(['message_key', 'message_en', 'message_am']);

    $this->people['chef']->forceFill(['is_active' => false])->save();
    $this->postJson('/api/v1/demo-login', ['role' => 'chef'])->assertStatus(422);

    $this->people['barista']->syncRoles(['waiter']);
    $this->postJson('/api/v1/demo-login', ['role' => 'barista'])->assertStatus(422);

    expect(array_column($this->getJson('/api/v1/demo-logins')->json('data'), 'role'))->not->toContain('chef', 'barista')
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
})->group('module:identity', 'feature');

it('never reaches anyone outside the demo branch\'s listed people', function () {
    config(['bakery.demo.login' => true]);

    // the second waiter and the shared pad's account of the demo branch are not offered
    makeUser($this->demo, 'waiter', ['phone' => DemoBakery::PAD_PHONE]);
    $this->people['waiter']->forceFill(['is_active' => false])->save();
    $this->postJson('/api/v1/demo-login', ['role' => 'waiter'])->assertStatus(422);

    // a shop branch with the same phones: never signed in, even when the demo branch is not a demo branch any more
    $shop = Branch::factory()->create(['name' => 'Shop']);
    makeUser($shop, 'admin', ['phone' => DemoLogins::PEOPLE['admin']]);
    $this->demo->forceFill(['settings' => []])->save();

    $this->postJson('/api/v1/demo-login', ['role' => 'admin'])->assertStatus(422);
    expect($this->getJson('/api/v1/demo-logins')->json('data'))->toBe([])
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
})->group('module:identity', 'feature');

it('is throttled like POST /login', function () {
    config(['bakery.demo.login' => true]);

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/demo-login', ['role' => 'owner'])->assertStatus(422);
    }
    $this->postJson('/api/v1/demo-login', ['role' => 'admin'])->assertStatus(429);
})->group('module:identity', 'feature');

it('offers the same people as the demo seeder: the first person of each role, by phone', function () {
    $first = [];
    foreach (DemoBakery::PEOPLE as $person) {
        $first[$person['role']] ??= $person['phone'];
    }

    expect(DemoLogins::PEOPLE)->toEqual($first);
})->group('module:identity', 'unit');
