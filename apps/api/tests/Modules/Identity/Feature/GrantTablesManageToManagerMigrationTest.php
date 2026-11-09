<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PermissionRegistry;
use App\Shared\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

pest()->group('module:identity', 'feature');

/*
 * Phase 6 follow-up (Admin > Tables; Ramin, 2026-11-09): the manager adds, renames and switches off dining tables as the admin does, so
 * tables.manage is granted to the manager in the registry and reaches the live database through its own grant-only migration (a deploy
 * never runs seeders). Same guarantees as every grant: never revokes, creates no role, writes nothing the second time, clears spatie's
 * cache. The start state is "the live database before this step": the roles and every grant exist, tables.manage is the admin's only.
 */

function tablesManageGrantMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_14_100000_grant_tables_manage_to_manager.php');

    return $migration;
}

function beforeTablesForManagers(): void
{
    seedRoles();
    Role::findByName('manager', 'web')->revokePermissionTo('tables.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** @return array<string, list<array<string, mixed>>> */
function tablesManageSnapshot(): array
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

it('has tables.manage in the registry for the admin and the manager', function () {
    expect(PermissionRegistry::rolesFor('tables.manage'))->toBe(['admin', 'manager']);
});

it('grants tables.manage to the manager, keeps the admin, and gives it to no other role', function () {
    beforeTablesForManagers();
    $branch = Branch::factory()->create();
    expect(Role::findByName('manager', 'web')->hasPermissionTo('tables.manage'))->toBeFalse();

    tablesManageGrantMigration()->up();

    expect(Permission::query()->where('name', 'tables.manage')->where('guard_name', 'web')->count())->toBe(1);
    foreach (PermissionRegistry::ROLES as $role) {
        expect(Role::findByName($role, 'web')->hasPermissionTo('tables.manage'))->toBe(in_array($role, ['admin', 'manager'], true), $role);
    }
    expect(makeUser($branch, 'manager')->fresh()?->can('tables.manage'))->toBeTrue('the cache was cleared')
        ->and(makeUser($branch, 'admin')->fresh()?->can('tables.manage'))->toBeTrue()
        ->and(makeUser($branch, 'waiter')->fresh()?->can('tables.manage'))->toBeFalse();
});

it('is the only grant migration that makes the manager\'s grant: every earlier one still does exactly what it did', function () {
    beforeTablesForManagers();
    $managerHolds = fn (): bool => Role::findByName('manager', 'web')->permissions()->where('name', 'tables.manage')->exists();

    $earlier = array_filter(
        glob(database_path('migrations/*_grant_*.php')) ?: [],
        fn (string $file): bool => strcmp(basename($file), '2026_11_14_100000') < 0,
    );
    expect(count($earlier))->toBeGreaterThanOrEqual(10);
    foreach ($earlier as $file) {
        /** @var Migration $migration */
        $migration = require $file;
        $migration->up();
        expect($managerHolds())->toBeFalse(basename($file));
    }

    tablesManageGrantMigration()->up();
    expect($managerHolds())->toBeTrue();

    // the registry as each migration knew it: the grant from its own migration on, and in today's matrix (RolesSeeder)
    expect(PermissionRegistry::permissionsFor('manager', '2026_11_13_100100'))->not->toContain('tables.manage')
        ->and(PermissionRegistry::permissionsFor('manager', '2026_11_14_100000'))->toContain('tables.manage')
        ->and(PermissionRegistry::permissionsFor('manager'))->toContain('tables.manage')
        ->and(PermissionRegistry::permissionsFor('admin', '2026_10_21_120100'))->toContain('tables.manage');
});

it('never revokes: a hand-made grant and a direct permission survive', function () {
    $branch = Branch::factory()->create();
    beforeTablesForManagers();
    $cashier = makeUser($branch, 'cashier');
    Role::findByName('waiter', 'web')->givePermissionTo(Permission::findOrCreate('reports.custom', 'web'));
    $cashier->givePermissionTo('tables.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    tablesManageGrantMigration()->up();

    expect(Role::findByName('waiter', 'web')->hasPermissionTo('reports.custom'))->toBeTrue()
        ->and($cashier->fresh()?->hasDirectPermission('tables.manage'))->toBeTrue()
        ->and(Permission::query()->where('name', 'tables.manage')->count())->toBe(1);
});

it('is a no-op the second time and on a database that already matches the registry, and does nothing on the way down', function () {
    beforeTablesForManagers();
    tablesManageGrantMigration()->up();
    $once = tablesManageSnapshot();
    tablesManageGrantMigration()->up();
    expect(tablesManageSnapshot())->toBe($once);

    seedRoles();
    $seeded = tablesManageSnapshot();
    tablesManageGrantMigration()->up();
    expect(tablesManageSnapshot())->toBe($seeded);

    tablesManageGrantMigration()->down();
    expect(tablesManageSnapshot())->toBe($seeded);
});
