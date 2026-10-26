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
 * Step 4.4b (phase-4 plan section 10.6): analytics.manage (the admin: the on-demand rebuild of the summary tables, later the weekly summary test)
 * reaches the live database through its own grant-only migration, because M2 (2026_10_30_100100_grant_phase_4_permissions) has run there already
 * and a deploy never runs seeders. Same guarantees as every grant: never revokes, creates no role, writes nothing the second time, clears spatie's
 * cache. The start state is "the live database after step 4.4a": the roles and the earlier grants exist, analytics.manage does not (the plan named it
 * for M2, which step 4.2b built without it).
 */

function analyticsManageGrantMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_05_100100_grant_analytics_manage_permission.php');

    return $migration;
}

function afterStepFourFourA(): void
{
    seedRoles();
    Permission::query()->where('name', 'analytics.manage')->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** @return array<string, list<array<string, mixed>>> */
function analyticsManageSnapshot(): array
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

it('has analytics.manage in the registry for the admin only', function () {
    expect(PermissionRegistry::isPermission('analytics.manage'))->toBeTrue()
        ->and(PermissionRegistry::rolesFor('analytics.manage'))->toBe(['admin']);
});

it('creates the permission and grants it to the admin only', function () {
    afterStepFourFourA();
    expect(Permission::query()->where('name', 'analytics.manage')->count())->toBe(0);

    analyticsManageGrantMigration()->up();

    expect(Permission::query()->where('name', 'analytics.manage')->where('guard_name', 'web')->count())->toBe(1);
    foreach (PermissionRegistry::ROLES as $role) {
        expect(Role::findByName($role, 'web')->hasPermissionTo('analytics.manage'))->toBe($role === 'admin', $role);
    }
    $branch = Branch::factory()->create();
    expect(makeUser($branch, 'admin')->fresh()?->can('analytics.manage'))->toBeTrue('the cache was cleared')
        ->and(makeUser($branch, 'manager')->fresh()?->can('analytics.manage'))->toBeFalse('the manager keeps "no configuration"');
});

it('never revokes: a hand-made grant, a direct permission and a manager given analytics.manage by hand survive', function () {
    $branch = Branch::factory()->create();
    afterStepFourFourA();
    $manager = makeUser($branch, 'manager');
    Role::findByName('waiter', 'web')->givePermissionTo(Permission::findOrCreate('reports.custom', 'web'));
    $manager->givePermissionTo(Permission::findOrCreate('analytics.manage', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    analyticsManageGrantMigration()->up();

    expect(Role::findByName('waiter', 'web')->hasPermissionTo('reports.custom'))->toBeTrue()
        ->and($manager->fresh()?->hasDirectPermission('analytics.manage'))->toBeTrue()
        ->and(Permission::query()->where('name', 'analytics.manage')->count())->toBe(1);
});

it('is a no-op the second time and on a database that already matches the registry, and does nothing on the way down', function () {
    afterStepFourFourA();
    analyticsManageGrantMigration()->up();
    $once = analyticsManageSnapshot();
    analyticsManageGrantMigration()->up();
    expect(analyticsManageSnapshot())->toBe($once);

    seedRoles();
    $seeded = analyticsManageSnapshot();
    analyticsManageGrantMigration()->up();
    expect(analyticsManageSnapshot())->toBe($seeded);

    analyticsManageGrantMigration()->down();
    expect(analyticsManageSnapshot())->toBe($seeded);
});
