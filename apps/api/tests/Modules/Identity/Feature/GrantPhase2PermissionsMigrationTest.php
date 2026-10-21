<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Shared\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Step 2.1 (phase-2 plan section 10.5): the four permissions Phase 2 adds to the registry (production.view,
 * production.plan, notifications.view, costs.view) reach the live database through a grant-only migration, because a
 * deploy never runs seeders. It is the step 1.3e pattern (tests/Modules/Orders/Feature/GrantNewPermissionsMigrationTest.php)
 * through the shared Identity\Support\GrantRegistryPermissions: it creates the registry permissions the `web` guard
 * lacks and attaches each to the default roles that exist; it never revokes, deletes, renames or updates anything,
 * creates no role, leaves users' roles and direct permissions alone, writes nothing the second time and clears
 * spatie's permission cache. The start state here is "a database seeded after Phase 1": the roles exist with the Phase 1
 * grants and the four permissions do not.
 */

const PHASE_2_PERMISSIONS = [
    'production.view' => ['admin', 'manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker'],
    'production.plan' => ['admin', 'manager'],
    'notifications.view' => ['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'],
    'costs.view' => ['admin', 'manager'],
];

function phase2GrantMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_22_100100_grant_phase_2_permissions.php');

    return $migration;
}

/** A database as it was after Phase 1: seeded from the registry, then the Phase 2 permissions taken away again. */
function afterPhaseOne(): void
{
    seedRoles();
    Permission::query()->whereIn('name', array_keys(PHASE_2_PERMISSIONS))->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** @return array<string, list<array<string, mixed>>> */
function phase2Snapshot(): array
{
    $rows = fn (string $table, string $order) => DB::table($table)->orderByRaw($order)->get()->map(fn ($r) => (array) $r)->all();

    return [
        'permissions' => $rows('permissions', 'id'),
        'roles' => $rows('roles', 'id'),
        'role_has_permissions' => $rows('role_has_permissions', 'role_id, permission_id'),
        'model_has_roles' => $rows('model_has_roles', 'role_id, model_id'),
        'model_has_permissions' => $rows('model_has_permissions', 'permission_id, model_id'),
    ];
}

/** @return list<string> */
function phase2PermissionsOf(string $role): array
{
    $names = Role::findByName($role, 'web')->permissions()->pluck('name')->all();
    sort($names);

    return array_values($names);
}

it('creates the four permissions and grants them to their default roles, as plan section 10.5 lists', function () {
    afterPhaseOne();
    expect(Permission::query()->whereIn('name', array_keys(PHASE_2_PERMISSIONS))->count())->toBe(0);

    phase2GrantMigration()->up();

    foreach (PHASE_2_PERMISSIONS as $name => $roles) {
        expect(Permission::query()->where('name', $name)->where('guard_name', 'web')->count())->toBe(1, $name);
        foreach (PermissionRegistry::ROLES as $role) {
            expect(in_array($name, phase2PermissionsOf($role), true))->toBe(in_array($role, $roles, true), "{$role} / {$name}");
        }
    }
    foreach (PermissionRegistry::ROLES as $role) {
        $expected = PermissionRegistry::permissionsFor($role);
        sort($expected);
        expect(phase2PermissionsOf($role))->toBe($expected, $role);
    }
})->group('module:identity', 'feature');

it('never revokes: a permission added by hand to a role, a direct permission and every role assignment survive', function () {
    $branch = Branch::factory()->create();
    seedRoles();
    $waiter = makeUser($branch, 'waiter');
    $chef = makeUser($branch, 'chef');
    Role::findByName('waiter', 'web')->givePermissionTo(Permission::findOrCreate('reports.custom', 'web'));
    $chef->givePermissionTo('inventory.adjust');
    Permission::query()->whereIn('name', array_keys(PHASE_2_PERMISSIONS))->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    phase2GrantMigration()->up();

    expect(phase2PermissionsOf('waiter'))->toContain('reports.custom', 'notifications.view')
        ->and(phase2PermissionsOf('waiter'))->not->toContain('production.view', 'costs.view')
        ->and($waiter->fresh()?->hasRole('waiter'))->toBeTrue()
        ->and($chef->fresh()?->hasDirectPermission('inventory.adjust'))->toBeTrue()
        ->and($chef->fresh()?->hasRole('chef'))->toBeTrue()
        ->and($chef->fresh()?->can('production.view'))->toBeTrue()
        ->and(DB::table('model_has_permissions')->count())->toBe(1);
})->group('module:identity', 'feature');

it('is a no-op the second time and on a database that already matches the registry', function () {
    afterPhaseOne();
    Role::findOrCreate('shift_lead', 'web')->givePermissionTo(Permission::findOrCreate('custom.one', 'web'));

    phase2GrantMigration()->up();
    $once = phase2Snapshot();
    phase2GrantMigration()->up();

    expect(phase2Snapshot())->toBe($once);

    seedRoles();
    $seeded = phase2Snapshot();
    phase2GrantMigration()->up();
    expect(phase2Snapshot())->toBe($seeded);
})->group('module:identity', 'feature');

it('works on a database no seeder has run on: it creates the permissions and, with no roles, creates no roles', function () {
    DB::table('permissions')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Permission::query()->count())->toBe(0)->and(Role::query()->count())->toBe(0);

    phase2GrantMigration()->up();

    expect(Permission::query()->where('guard_name', 'web')->pluck('name')->all())->toEqualCanonicalizing(PermissionRegistry::permissions())
        ->and(Role::query()->count())->toBe(0)
        ->and(DB::table('role_has_permissions')->count())->toBe(0);
})->group('module:identity', 'feature');

it('grants only the roles that exist and leaves other roles and other guards alone', function () {
    afterPhaseOne();
    Role::query()->where('name', 'bread_baker')->where('guard_name', 'web')->firstOrFail()->delete();
    Role::findOrCreate('admin', 'sanctum')->givePermissionTo(Permission::findOrCreate('catalog.manage', 'sanctum'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    phase2GrantMigration()->up();

    expect(Role::query()->where('name', 'bread_baker')->exists())->toBeFalse()
        ->and(Role::findByName('admin', 'sanctum')->permissions()->pluck('name')->all())->toBe(['catalog.manage'])
        ->and(Permission::query()->where('name', 'costs.view')->count())->toBe(1)
        ->and(phase2PermissionsOf('admin'))->toContain('costs.view', 'production.plan');
})->group('module:identity', 'feature');

it('clears the permission cache so the new grant is seen at once', function () {
    afterPhaseOne();
    $registrar = app(PermissionRegistrar::class);
    expect($registrar->getPermissions()->pluck('name')->all())->not->toContain('costs.view'); // cache now warm

    phase2GrantMigration()->up();

    expect($registrar->getPermissions()->pluck('name')->all())->toContain('costs.view');
    $branch = Branch::factory()->create();
    expect(makeUser($branch, 'manager')->fresh()?->can('costs.view'))->toBeTrue()
        ->and(makeUser($branch, 'cashier')->fresh()?->can('costs.view'))->toBeFalse();
})->group('module:identity', 'feature');

it('does nothing on the way down: what it granted may be in use', function () {
    afterPhaseOne();
    phase2GrantMigration()->up();
    $after = phase2Snapshot();

    phase2GrantMigration()->down();

    expect(phase2Snapshot())->toBe($after)
        ->and(phase2PermissionsOf('admin'))->toContain('costs.view');
})->group('module:identity', 'feature');
