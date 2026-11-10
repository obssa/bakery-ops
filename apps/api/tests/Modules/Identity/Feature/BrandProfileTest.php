<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Modules\Identity\Models\BrandProfile;
use App\Shared\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Phase 6 follow-up (the bakery's own brand, Ramin 2026-11-10): the business name and logo the app shows, set by the admin on the live site
 * so the public repository never holds them. GET /me carries `brand` for everyone signed in; PUT /brand (the name pair, both or neither),
 * POST /brand/logo (a JPEG, PNG or WebP, kept whole on a white 256 px square) and DELETE /brand/logo are branding.manage (the admin's),
 * idempotent and audited. The logo's file is public at /api/v1/media/brand/{sha}.{ext}, content-addressed and cached as immutable.
 * The 403 of every other role, the replay and the one new row per first write are the registries' (route_permissions, write_endpoints).
 */

pest()->group('module:identity', 'feature');

beforeEach(function () {
    Storage::fake(config('catalog.images.disk'));
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $this->send = function (string $method, string $uri, array $body = []) {
        loginAs($this->admin);

        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->json($method, $uri, $body);
    };
    $this->upload = function (string $bytes, string $name = 'logo.png') {
        loginAs($this->admin);

        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->post('/api/v1/brand/logo', ['logo' => UploadedFile::fake()->createWithContent($name, $bytes)], ['Accept' => 'application/json']);
    };
});

it('shows every signed-in person the app\'s own name and mark while the admin has set none', function (string $role) {
    loginAs(makeUser($this->branch, $role));

    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('brand', ['name_en' => null, 'name_am' => null, 'logo' => null, 'is_default' => true, 'clock_also' => 'none']);
})->with(['admin', 'waiter', 'cake_baker']);

it('saves the name pair on the first save, audited, and everyone reads it in GET /me', function () {
    ($this->send)('PUT', '/api/v1/brand', ['name_en' => '  Corner Bakery ', 'name_am' => 'የማዕዘን ዳቦ ቤት'])->assertOk()
        ->assertJsonPath('data.name_en', 'Corner Bakery')
        ->assertJsonPath('data.name_am', 'የማዕዘን ዳቦ ቤት')
        ->assertJsonPath('data.is_default', false);

    expect(BrandProfile::query()->count())->toBe(1);
    $audit = DB::table('audit_log')->where('action', 'identity.brand.update')->sole();
    expect((int) $audit->actor_id)->toBe($this->admin->id)
        ->and(json_decode((string) $audit->after, true)['name_en'])->toBe('Corner Bakery');

    loginAs(makeUser($this->branch, 'waiter'));
    $this->getJson('/api/v1/me')->assertJsonPath('brand.name_en', 'Corner Bakery')->assertJsonPath('brand.name_am', 'የማዕዘን ዳቦ ቤት');
});

it('asks for both names or neither, and neither puts the app\'s own name back', function () {
    ($this->send)('PUT', '/api/v1/brand', ['name_en' => 'Corner Bakery', 'name_am' => null])
        ->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->send)('PUT', '/api/v1/brand', ['name_en' => str_repeat('a', 121), 'name_am' => 'ሀ'])->assertStatus(422);
    ($this->send)('PUT', '/api/v1/brand', ['name_en' => 'Corner Bakery', 'name_am' => 'የማዕዘን ዳቦ ቤት'])->assertOk();

    ($this->send)('PUT', '/api/v1/brand', ['name_en' => '', 'name_am' => ''])->assertOk()
        ->assertJsonPath('data.name_en', null)->assertJsonPath('data.name_am', null)->assertJsonPath('data.is_default', true);
    expect(BrandProfile::query()->count())->toBe(1);
});

it('keeps a logo whole on a white square, serves it publicly and immutable, and answers 304 to its ETag', function () {
    $response = ($this->upload)(imgPng(400, 100, 127))->assertOk();
    $url = (string) $response->json('data.logo.url');
    expect($url)->toMatch('#^/api/v1/media/brand/[0-9a-f]{64}\.(webp|jpg)$#')
        ->and($response->json('data.logo.size'))->toBe(256)
        ->and(DB::table('audit_log')->where('action', 'identity.brand.logo_set')->count())->toBe(1);

    $file = $this->get($url);
    $file->assertOk();
    $bytes = $file->streamedContent();
    $info = getimagesizefromstring($bytes);
    expect($info[0])->toBe(256)->and($info[1])->toBe(256)
        ->and($file->headers->get('Cache-Control'))->toBe('immutable, max-age=31536000, public')
        ->and($file->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($file->headers->getCookies())->toBe([]);
    // a transparent logo is drawn on white, never black
    $image = imagecreatefromstring($bytes);
    $rgb = imagecolorat($image, 128, 128);
    expect(($rgb >> 16) & 0xFF)->toBeGreaterThan(240)->and($rgb & 0xFF)->toBeGreaterThan(240);

    $this->withHeaders(['If-None-Match' => (string) $file->headers->get('ETag')])->get($url)->assertStatus(304);
    expect(Route::getRoutes()->getByName('identity.brand.media')?->gatherMiddleware())->toBe(['api'], 'no auth, no actor, no session');
});

it('refuses a file that is not an image, stores nothing and audits nothing', function () {
    ($this->upload)('not a picture at all', 'logo.png')->assertStatus(422)->assertJsonPath('message_key', 'files.not_an_image');

    expect(BrandProfile::query()->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'like', 'identity.brand.%')->count())->toBe(0);
});

it('removes the logo (audited) and the old URL stops answering; removing none writes nothing', function () {
    $url = (string) ($this->upload)(imgJpeg(300, 300))->json('data.logo.url');

    ($this->send)('DELETE', '/api/v1/brand/logo')->assertOk()->assertJsonPath('data.logo', null);
    $this->get($url)->assertNotFound();
    expect(DB::table('audit_log')->where('action', 'identity.brand.logo_removed')->count())->toBe(1);

    ($this->send)('DELETE', '/api/v1/brand/logo')->assertOk()->assertJsonPath('data.logo', null);
    expect(DB::table('audit_log')->where('action', 'identity.brand.logo_removed')->count())->toBe(1);
});

it('saves the international clock beside the Ethiopian one, audited, keeps the name and logo, and everyone reads it', function () {
    ($this->send)('PUT', '/api/v1/brand/clock', ['clock_also' => 'h12'])->assertOk()
        ->assertJsonPath('data.clock_also', 'h12')
        ->assertJsonPath('data.is_default', true);
    $audit = DB::table('audit_log')->where('action', 'identity.brand.clock')->sole();
    expect(json_decode((string) $audit->after, true)['clock_also'])->toBe('h12');

    ($this->send)('PUT', '/api/v1/brand', ['name_en' => 'Corner Bakery', 'name_am' => 'የማዕዘን ዳቦ ቤት'])->assertOk()->assertJsonPath('data.clock_also', 'h12');
    ($this->send)('PUT', '/api/v1/brand/clock', ['clock_also' => 'h24'])->assertOk()
        ->assertJsonPath('data.clock_also', 'h24')
        ->assertJsonPath('data.name_en', 'Corner Bakery');

    loginAs(makeUser($this->branch, 'cashier'));
    $this->getJson('/api/v1/me')->assertJsonPath('brand.clock_also', 'h24');

    ($this->send)('PUT', '/api/v1/brand/clock', ['clock_also' => 'none'])->assertOk()->assertJsonPath('data.clock_also', 'none');
    expect(BrandProfile::query()->count())->toBe(1);
});

it('refuses a clock that is not none, h12 or h24', function (mixed $also) {
    ($this->send)('PUT', '/api/v1/brand/clock', ['clock_also' => $also])
        ->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    expect(BrandProfile::query()->count())->toBe(0);
})->with(['12h', 'H24', '', null, 'ethiopian']);

it('answers 404 for an unknown sha or the wrong extension', function () {
    $url = (string) ($this->upload)(imgJpeg(300, 300))->json('data.logo.url');
    $other = str_ends_with($url, '.webp') ? substr($url, 0, -4).'jpg' : substr($url, 0, -3).'webp';

    $this->get($other)->assertNotFound();
    $this->get('/api/v1/media/brand/'.str_repeat('a', 64).'.jpg')->assertNotFound();
});

it('gives branding.manage to the admin alone through its grant-only migration', function () {
    Permission::query()->where('name', 'branding.manage')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_15_100000_grant_branding_permission.php');
    $migration->up();
    $migration->up();

    expect(PermissionRegistry::rolesFor('branding.manage'))->toBe(['admin'])
        ->and(Role::findByName('admin', 'web')->hasPermissionTo('branding.manage'))->toBeTrue()
        ->and(Role::findByName('manager', 'web')->hasPermissionTo('branding.manage'))->toBeFalse()
        ->and(Permission::query()->where('name', 'branding.manage')->count())->toBe(1);
});
