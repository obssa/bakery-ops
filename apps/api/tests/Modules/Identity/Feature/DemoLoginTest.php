<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Demo\DemoLogins;
use App\Shared\Models\Branch;
use Database\Seeders\Demo\DemoBakery;
use Illuminate\Support\Facades\DB;

/*
 * TEMPORARY (ADR 0025, Ramin 2026-11-12): the sign-in page's one-click demo buttons. Off by default and then invisible (404); on (the demo
 * branch's admin switch, brand_profiles.demo_login, unless BAKERY_DEMO_LOGIN=false forces them off), only the listed demo people of the
 * demo branch can be signed in, with the same answer and audit row as POST /login. The switch's own route: DemoLoginSwitchTest.
 */

beforeEach(function () {
    seedRoles();
    $this->demo = Branch::factory()->create(['name' => 'Demo Bakery', 'settings' => ['demo' => true]]);
    $this->people = [];
    foreach (DemoLogins::PEOPLE as $role => $phone) {
        $this->people[$role] = makeUser($this->demo, $role, ['phone' => $phone, 'name' => "Person {$role} (ሰው {$role})", 'locale' => 'am']);
    }
    config(['bakery.demo.login_forced_off' => false]);
    // the demo branch's switch as the admin's PUT /brand/demo-login leaves it (DemoLoginSwitchTest drives the route itself)
    $this->turn = function (bool $on): void {
        $now = now();
        DB::table('brand_profiles')->updateOrInsert(
            ['branch_id' => $this->demo->id],
            ['demo_login' => $on, 'created_by_user_id' => $this->people['admin']->id, 'created_at' => $now, 'updated_at' => $now],
        );
    };
});

it('answers 404 on both routes while the switch is off (no row, or switched off), and signs nobody in', function () {
    $this->getJson('/api/v1/demo-logins')->assertNotFound();
    $this->postJson('/api/v1/demo-login', ['role' => 'admin'])->assertNotFound();

    ($this->turn)(false);
    $this->getJson('/api/v1/demo-logins')->assertNotFound();
    $this->postJson('/api/v1/demo-login', ['role' => 'admin'])->assertNotFound();

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
})->group('module:identity', 'feature');

it('stays off while the server forces it off (BAKERY_DEMO_LOGIN=false), whatever the switch says', function () {
    ($this->turn)(true);
    $this->getJson('/api/v1/demo-logins')->assertOk();

    config(['bakery.demo.login_forced_off' => true]);
    $this->getJson('/api/v1/demo-logins')->assertNotFound();
    $this->postJson('/api/v1/demo-login', ['role' => 'admin'])->assertNotFound();
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
})->group('module:identity', 'feature');

it('reads only the demo branch\'s switch: another branch switched on opens nothing', function () {
    $shop = Branch::factory()->create(['name' => 'Shop']);
    $owner = makeUser($shop, 'admin');
    DB::table('brand_profiles')->insert(['branch_id' => $shop->id, 'demo_login' => true, 'created_by_user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->getJson('/api/v1/demo-logins')->assertNotFound();
})->group('module:identity', 'feature');

it('lists one demo person per role, in order, with both names and no phone or code', function () {
    ($this->turn)(true);

    $response = $this->getJson('/api/v1/demo-logins')->assertOk();

    expect(array_column($response->json('data'), 'role'))->toBe(array_keys(DemoLogins::PEOPLE))
        ->and($response->json('data.0'))->toBe(['role' => 'admin', 'name_en' => 'Person admin', 'name_am' => 'ሰው admin'])
        ->and($response->getContent())->not->toContain('+2519')->not->toContain('short_code');
})->group('module:identity', 'feature');

it('signs each role in with POST /login\'s answer and an auth.login audit row marked via=demo_button', function () {
    ($this->turn)(true);

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
    ($this->turn)(true);

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
    ($this->turn)(true);

    // the second waiter and the shared pad's account of the demo branch are not offered
    makeUser($this->demo, 'waiter', ['phone' => DemoBakery::PAD_PHONE]);
    $this->people['waiter']->forceFill(['is_active' => false])->save();
    $this->postJson('/api/v1/demo-login', ['role' => 'waiter'])->assertStatus(422);

    // a shop branch with the same phones: never signed in; and once the demo branch is not a demo branch any more, its switch no longer
    // counts, so both routes are gone
    $shop = Branch::factory()->create(['name' => 'Shop']);
    makeUser($shop, 'admin', ['phone' => DemoLogins::PEOPLE['admin']]);
    $this->demo->forceFill(['settings' => []])->save();

    $this->postJson('/api/v1/demo-login', ['role' => 'admin'])->assertNotFound();
    $this->getJson('/api/v1/demo-logins')->assertNotFound();
    expect(DB::table('personal_access_tokens')->count())->toBe(0);
})->group('module:identity', 'feature');

it('is throttled like POST /login', function () {
    ($this->turn)(true);

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
