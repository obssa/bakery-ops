<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Support\Facades\Route;

/*
 * Contract: catalog -> identity (manifest contracts["catalog->identity"]).
 *
 * Written BEFORE any code exists in App\Modules\Catalog. It instantiates only Identity classes (Access,
 * PermissionRegistry, the `actor` middleware, spatie's `permission:` middleware wired by Identity) and factories.
 * Each case is phrased from Catalog's side: "when I, Catalog, guard my routes the way Identity's own routes are
 * guarded (auth:sanctum, actor, permission:<name>, idempotency on writes), I rely on ...".
 *
 * Catalog has no routes yet, so the cases mount probe routes with exactly the middleware stack every Catalog route
 * will carry (routes/modules/identity.php is the model). The probe handler reports the request context Identity
 * resolved, which is what Catalog's AuditedAction writes will stamp. It runs in Identity's CI job (mod-identity)
 * for the life of the project, so a later Identity change that breaks these expectations fails before Catalog runs.
 */

/** The middleware every Catalog route carries; writes add `idempotency` (rule 7). */
function catalogProbeRoutes(): void
{
    $handler = static fn () => response()->json([
        'ran' => true,
        'branch_id' => app(CurrentBranch::class)->idOrNull(),
        'actor' => app(ActorContext::class)->userId(),
    ]);

    Route::prefix('api/v1/contract-probe/catalog')->middleware(['api', 'auth:sanctum', 'actor'])->group(function () use ($handler): void {
        Route::get('/read', $handler)->middleware('permission:catalog.view');
        Route::post('/write', $handler)->middleware(['permission:catalog.manage', 'idempotency']);
        Route::put('/write', $handler)->middleware(['permission:catalog.manage', 'idempotency']);
        Route::patch('/write', $handler)->middleware(['permission:catalog.manage', 'idempotency']);
        Route::delete('/write', $handler)->middleware(['permission:catalog.manage', 'idempotency']);
    });
}

$nonAdmin = array_values(array_diff(PermissionRegistry::ROLES, ['admin']));
$writes = ['POST', 'PUT', 'PATCH', 'DELETE'];

$writeCases = [];
foreach ($nonAdmin as $role) {
    foreach ($writes as $method) {
        $writeCases["{$method} as {$role}"] = [$role, $method];
    }
}

beforeEach(function () {
    seedRoles();
    catalogProbeRoutes();
    $this->branch = Branch::factory()->create();
});

it('denies catalog.manage to every non-admin role through Access::can', function (string $role) {
    $user = makeUser($this->branch, $role);

    expect(app(Access::class)->can($user, 'catalog.manage'))->toBeFalse("{$role} must not manage the catalog")
        ->and(PermissionRegistry::rolesFor('catalog.manage'))->toBe(['admin']);
})->with($nonAdmin)->group('contract:catalog->identity', 'contract');

it('answers every catalog write with 403 auth.forbidden for every non-admin role, before the handler runs', function (string $role, string $method) {
    loginAs(makeUser($this->branch, $role));

    $response = $this->withHeaders(idempotencyKey())->json($method, '/api/v1/contract-probe/catalog/write', ['name_en' => 'Bread', 'name_am' => 'ዳቦ']);

    $response->assertStatus(403)
        ->assertJsonPath('message_key', 'auth.forbidden')
        ->assertJsonMissingPath('ran');
    expect($response->json('message_en'))->toBeString()->not->toBe('')
        ->and($response->json('message_am'))->toBeString()->not->toBe('');
})->with($writeCases)->group('contract:catalog->identity', 'contract');

it('lets admin pass can(\'catalog.manage\') and every guarded catalog write, with branch and actor resolved', function (string $method) {
    $admin = loginAs(makeUser($this->branch, 'admin'));

    expect(app(Access::class)->can($admin, 'catalog.manage'))->toBeTrue();

    $this->withHeaders(idempotencyKey())->json($method, '/api/v1/contract-probe/catalog/write', [])
        ->assertOk()
        ->assertJsonPath('ran', true)
        ->assertJsonPath('branch_id', $this->branch->id)
        ->assertJsonPath('actor', $admin->id);
})->with($writes)->group('contract:catalog->identity', 'contract');

it('lets every role read the catalog: catalog.view is held by all eight roles', function (string $role) {
    $user = loginAs(makeUser($this->branch, $role));

    expect(app(Access::class)->can($user, 'catalog.view'))->toBeTrue("{$role} must read the catalog")
        ->and(PermissionRegistry::rolesFor('catalog.view'))->toEqualCanonicalizing(PermissionRegistry::ROLES);

    $this->getJson('/api/v1/contract-probe/catalog/read')
        ->assertOk()
        ->assertJsonPath('ran', true)
        ->assertJsonPath('branch_id', $this->branch->id)
        ->assertJsonPath('actor', $user->id);
})->with(PermissionRegistry::ROLES)->group('contract:catalog->identity', 'contract');

it('honours a per-user grant of catalog.manage (admin overrides the matrix per user)', function () {
    $manager = makeUser($this->branch, 'manager');
    $manager->givePermissionTo('catalog.manage');
    $manager = $manager->fresh() ?? $manager;
    loginAs($manager);

    expect(app(Access::class)->can($manager, 'catalog.manage'))->toBeTrue();
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/contract-probe/catalog/write', [])
        ->assertOk()
        ->assertJsonPath('actor', $manager->id);
})->group('contract:catalog->identity', 'contract');

it('refuses an inactive admin and an anonymous caller', function () {
    $inactive = makeUser($this->branch, 'admin', ['is_active' => false]);

    expect(app(Access::class)->can($inactive, 'catalog.manage'))->toBeFalse();

    $this->withHeaders(idempotencyKey())->postJson('/api/v1/contract-probe/catalog/write', [])
        ->assertStatus(401)
        ->assertJsonPath('message_key', 'auth.unauthenticated');

    loginAs($inactive);
    $this->withHeaders(idempotencyKey())->postJson('/api/v1/contract-probe/catalog/write', [])
        ->assertStatus(403)
        ->assertJsonMissingPath('ran');
})->group('contract:catalog->identity', 'contract');

it('requires an Idempotency-Key on a guarded catalog write even for admin', function () {
    loginAs(makeUser($this->branch, 'admin'));

    $this->postJson('/api/v1/contract-probe/catalog/write', [])
        ->assertStatus(400)
        ->assertJsonPath('message_key', 'idempotency.key_required')
        ->assertJsonMissingPath('ran');
})->group('contract:catalog->identity', 'contract');
