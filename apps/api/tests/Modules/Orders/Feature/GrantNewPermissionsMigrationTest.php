<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * The migration that brings an existing database up to Identity's PermissionRegistry (step 1.3e; the live database was
 * seeded in Phase 0, before `tables.manage` existed, and RolesSeeder is not run by a deploy). It is grant-only: it
 * creates every registry permission spatie's tables lack (guard `web`, the guard RolesSeeder uses) and attaches each to
 * its default roles that exist; it never revokes, deletes or renames anything, never creates a role and never touches
 * the roles of users or their direct permissions. Running it twice changes nothing, it does not need a seeder to have
 * run, and it clears spatie's permission cache. Its down() does nothing: a permission or grant added here may have
 * been used and handed out since, so rolling back must not delete them.
 */

function grantMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_21_120100_grant_new_permissions.php');

    return $migration;
}

/** @return array<string, list<array<string, mixed>>> the whole of spatie's state, for before-and-after comparisons */
function spatieSnapshot(): array
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
function permissionsOf(string $role): array
{
    $names = Role::findByName($role, 'web')->permissions()->pluck('name')->all();
    sort($names);

    return array_values($names);
}

it('adds a permission the database lacks and grants it to its default roles', function () {
    seedRoles();
    Permission::findByName('tables.manage', 'web')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Permission::query()->where('name', 'tables.manage')->exists())->toBeFalse()
        ->and(permissionsOf('admin'))->not->toContain('tables.manage');

    grantMigration()->up();

    $permission = Permission::query()->where('name', 'tables.manage')->firstOrFail();
    expect($permission->guard_name)->toBe('web')
        ->and(permissionsOf('admin'))->toContain('tables.manage');
    foreach (['manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        expect(permissionsOf($role))->not->toContain('tables.manage', $role);
    }
    foreach (PermissionRegistry::ROLES as $role) {
        $expected = PermissionRegistry::permissionsFor($role);
        sort($expected);
        expect(permissionsOf($role))->toBe($expected, $role);
    }
})->group('module:orders', 'feature');

it('never revokes: a permission added by hand to a role, a direct permission and every role assignment survive', function () {
    seedRoles();
    $branch = App\Shared\Models\Branch::factory()->create();
    $waiter = makeUser($branch, 'waiter');
    $chef = makeUser($branch, 'chef');
    Role::findByName('waiter', 'web')->givePermissionTo(Permission::findOrCreate('reports.custom', 'web'));
    $chef->givePermissionTo('inventory.adjust');
    Permission::findByName('tables.manage', 'web')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    grantMigration()->up();

    expect(permissionsOf('waiter'))->toContain('reports.custom')
        ->and(Permission::query()->where('name', 'reports.custom')->exists())->toBeTrue()
        ->and($waiter->fresh()?->hasRole('waiter'))->toBeTrue()
        ->and($chef->fresh()?->hasDirectPermission('inventory.adjust'))->toBeTrue()
        ->and($chef->fresh()?->hasRole('chef'))->toBeTrue()
        ->and(DB::table('model_has_permissions')->count())->toBe(1);
})->group('module:orders', 'feature');

it('is a no-op the second time and on a database that already matches the registry', function () {
    seedRoles();
    Permission::findByName('tables.manage', 'web')->delete();
    Role::findOrCreate('shift_lead', 'web')->givePermissionTo(Permission::findOrCreate('custom.one', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    grantMigration()->up();
    $once = spatieSnapshot();
    grantMigration()->up();
    $twice = spatieSnapshot();

    expect($twice)->toBe($once);

    seedRoles();
    $seeded = spatieSnapshot();
    grantMigration()->up();
    expect(spatieSnapshot())->toBe($seeded);
})->group('module:orders', 'feature');

it('works on a database no seeder has run on: it creates the permissions and, with no roles, creates no roles', function () {
    expect(Permission::query()->count())->toBe(0)->and(Role::query()->count())->toBe(0);

    grantMigration()->up();

    $names = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
    expect($names)->toEqualCanonicalizing(PermissionRegistry::permissions())
        ->and(Role::query()->count())->toBe(0)
        ->and(DB::table('role_has_permissions')->count())->toBe(0);

    // the seeder later finds the permissions in place and grants the roles
    seedRoles();
    expect(Permission::query()->count())->toBe(count(PermissionRegistry::permissions()))
        ->and(permissionsOf('admin'))->toContain('tables.manage');
})->group('module:orders', 'feature');

it('grants only the roles that exist and leaves other roles and other guards alone', function () {
    seedRoles();
    Role::query()->where('name', 'bread_baker')->where('guard_name', 'web')->firstOrFail()->delete();
    Role::findOrCreate('admin', 'sanctum')->givePermissionTo(Permission::findOrCreate('catalog.manage', 'sanctum'));
    Permission::query()->where('name', 'tables.manage')->where('guard_name', 'web')->firstOrFail()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $before = DB::table('role_has_permissions')->count();

    grantMigration()->up();

    expect(Role::query()->where('name', 'bread_baker')->exists())->toBeFalse()
        ->and(DB::table('role_has_permissions')->count())->toBe($before + 1)
        ->and(Role::findByName('admin', 'sanctum')->permissions()->pluck('name')->all())->toBe(['catalog.manage'])
        ->and(Permission::query()->where('name', 'tables.manage')->count())->toBe(1);
})->group('module:orders', 'feature');

it('clears the permission cache so the new grant is seen at once', function () {
    seedRoles();
    Permission::findByName('tables.manage', 'web')->delete();
    $registrar = app(PermissionRegistrar::class);
    $registrar->forgetCachedPermissions();
    expect($registrar->getPermissions()->pluck('name')->all())->not->toContain('tables.manage'); // cache now warm

    grantMigration()->up();

    expect($registrar->getPermissions()->pluck('name')->all())->toContain('tables.manage');
    $branch = App\Shared\Models\Branch::factory()->create();
    expect(makeUser($branch, 'admin')->fresh()?->can('tables.manage'))->toBeTrue();
})->group('module:orders', 'feature');

it('does nothing on the way down: what it granted may be in use', function () {
    seedRoles();
    Permission::findByName('tables.manage', 'web')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    grantMigration()->up();
    $after = spatieSnapshot();

    grantMigration()->down();

    expect(spatieSnapshot())->toBe($after)
        ->and(permissionsOf('admin'))->toContain('tables.manage');
})->group('module:orders', 'feature');
