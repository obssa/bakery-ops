<?php

declare(strict_types=1);

use App\Modules\Identity\Demo\DemoLogins;
use App\Modules\Identity\Models\BrandProfile;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * TEMPORARY (ADR 0025, Ramin 2026-11-12): the switch of the sign-in page's one-click demo buttons in Admin > System, so the admin turns
 * them on and off in the browser instead of editing the server's .env. GET and PUT /brand/demo-login are branding.manage (the admin's);
 * only the demo branch's admin holds the switch; BAKERY_DEMO_LOGIN=false is the server's kill switch. The 403 of every other role and the
 * one new row of the first write are also the registries' (route_permissions, write_endpoints).
 */

pest()->group('module:identity', 'feature');

beforeEach(function () {
    seedRoles();
    config(['bakery.demo.login_forced_off' => false]);
    $this->demo = actInBranch(Branch::factory()->create(['name' => (string) config('bakery.demo.branch'), 'settings' => ['demo' => true]]));
    $this->admin = makeUser($this->demo, 'admin', ['phone' => DemoLogins::PEOPLE['admin'], 'name' => 'Dawit Bekele (ዳዊት በቀለ)']);
    $this->send = function (bool $on, ?string $key = null) {
        loginAs($this->admin);

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])->putJson('/api/v1/brand/demo-login', ['on' => $on]);
    };
});

it('starts off, and the demo branch\'s admin may switch it', function () {
    loginAs($this->admin);

    $this->getJson('/api/v1/brand/demo-login')->assertOk()
        ->assertExactJson(['data' => ['on' => false, 'available' => true, 'forced_off' => false]]);
});

it('turns the buttons on and off, audited with before and after, on the demo branch\'s one row', function () {
    ($this->send)(true)->assertOk()->assertExactJson(['data' => ['on' => true, 'available' => true, 'forced_off' => false]]);
    $this->getJson('/api/v1/demo-logins')->assertOk()->assertJsonPath('data.0.role', 'admin');

    ($this->send)(false)->assertOk()->assertJsonPath('data.on', false);
    $this->getJson('/api/v1/demo-logins')->assertNotFound();

    expect(BrandProfile::query()->count())->toBe(1);
    $audits = DB::table('audit_log')->where('action', 'identity.brand.demo_login')->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and((int) $audits[0]->actor_id)->toBe($this->admin->id)
        // the before image is the row as read back (1/0), the after image the saved model (true/false)
        ->and((bool) json_decode((string) $audits[0]->after, true)['demo_login'])->toBeTrue()
        ->and((bool) json_decode((string) $audits[1]->before, true)['demo_login'])->toBeTrue()
        ->and((bool) json_decode((string) $audits[1]->after, true)['demo_login'])->toBeFalse();
});

it('keeps the name, logo and clock the admin set', function () {
    loginAs($this->admin);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->putJson('/api/v1/brand', ['name_en' => 'Corner Bakery', 'name_am' => 'የማዕዘን ዳቦ ቤት'])->assertOk();

    ($this->send)(true)->assertOk();

    $this->getJson('/api/v1/me')->assertJsonPath('brand.name_en', 'Corner Bakery');
    expect(BrandProfile::query()->sole()->demo_login)->toBeTrue();
});

it('answers a replay with the first answer and writes once', function () {
    $key = (string) Str::uuid();
    ($this->send)(true, $key)->assertOk()->assertJsonPath('data.on', true);
    ($this->send)(true, $key)->assertOk()->assertJsonPath('data.on', true);

    expect(DB::table('audit_log')->where('action', 'identity.brand.demo_login')->count())->toBe(1);
});

it('shows the server\'s kill switch and keeps the buttons off under it, while the switch still saves', function () {
    config(['bakery.demo.login_forced_off' => true]);

    ($this->send)(true)->assertOk()->assertExactJson(['data' => ['on' => true, 'available' => true, 'forced_off' => true]]);
    $this->getJson('/api/v1/demo-logins')->assertNotFound();

    config(['bakery.demo.login_forced_off' => false]);
    $this->getJson('/api/v1/demo-logins')->assertOk();
});

it('refuses the switch to another branch\'s admin with a bilingual 409, and shows it as not theirs', function () {
    $shop = actInBranch(Branch::factory()->create(['name' => 'Shop']));
    loginAs(makeUser($shop, 'admin'));

    $this->getJson('/api/v1/brand/demo-login')->assertOk()
        ->assertExactJson(['data' => ['on' => false, 'available' => false, 'forced_off' => false]]);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->putJson('/api/v1/brand/demo-login', ['on' => true])
        ->assertStatus(409)
        ->assertJsonPath('message_key', 'identity.demo_login.not_demo_branch')
        ->assertJsonStructure(['message_key', 'message_en', 'message_am']);

    expect(BrandProfile::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses every role but the admin', function (string $role) {
    loginAs(makeUser($this->demo, $role));

    $this->getJson('/api/v1/brand/demo-login')->assertForbidden();
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->putJson('/api/v1/brand/demo-login', ['on' => true])->assertForbidden();
})->with(['manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker']);

it('asks for a boolean', function (mixed $on) {
    loginAs($this->admin);

    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->putJson('/api/v1/brand/demo-login', ['on' => $on])
        ->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
})->with(['yes', null, 2]);
