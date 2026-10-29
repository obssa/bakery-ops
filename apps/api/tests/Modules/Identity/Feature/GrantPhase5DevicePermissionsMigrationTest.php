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
 * Step 5.1a (phase-5 plan 3 M2a and 10.6): devices.manage (the admin: the device routes, split out of people.manage) and devices.self
 * (every role: GET /devices/this) reach the live database through a grant-only migration, because a deploy never runs seeders. Same
 * guarantees as every grant: never revokes, creates no role, writes nothing the second time, clears spatie's cache. The start state is
 * "the live database before step 5.1a": the roles and every earlier grant exist (people.manage since Phase 0), the two new ones do not.
 */

function phaseFiveDeviceGrantMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_11_100200_grant_phase_5_device_permissions.php');

    return $migration;
}

function beforeStepFiveOneA(): void
{
    seedRoles();
    Permission::query()->whereIn('name', ['devices.manage', 'devices.self'])->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** @return array<string, list<array<string, mixed>>> */
function phaseFiveDeviceGrantSnapshot(): array
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

it('has devices.manage for the admin and devices.self for every role in the registry', function () {
    expect(PermissionRegistry::rolesFor('devices.manage'))->toBe(['admin'])
        ->and(PermissionRegistry::rolesFor('devices.self'))->toBe(PermissionRegistry::ROLES)
        ->and(PermissionRegistry::rolesFor('people.manage'))->toBe(['admin']);
});

it('creates the two permissions and grants them, devices.manage to the admin only', function () {
    beforeStepFiveOneA();
    $grantsBefore = DB::table('role_has_permissions')->count();

    phaseFiveDeviceGrantMigration()->up();

    expect(Permission::query()->whereIn('name', ['devices.manage', 'devices.self'])->where('guard_name', 'web')->count())->toBe(2)
        ->and(DB::table('role_has_permissions')->count())->toBe($grantsBefore + 1 + count(PermissionRegistry::ROLES));
    foreach (PermissionRegistry::ROLES as $role) {
        expect(Role::findByName($role, 'web')->hasPermissionTo('devices.manage'))->toBe($role === 'admin', $role)
            ->and(Role::findByName($role, 'web')->hasPermissionTo('devices.self'))->toBeTrue($role);
    }
    $branch = Branch::factory()->create();
    expect(makeUser($branch, 'admin')->fresh()?->can('devices.manage'))->toBeTrue('the cache was cleared')
        ->and(makeUser($branch, 'waiter')->fresh()?->can('devices.self'))->toBeTrue()
        ->and(makeUser($branch, 'admin')->fresh()?->can('people.manage'))->toBeTrue('people.manage is untouched');
});

it('never revokes: a hand-made grant, a direct permission and a manager given devices.manage by hand survive', function () {
    $branch = Branch::factory()->create();
    beforeStepFiveOneA();
    $manager = makeUser($branch, 'manager');
    Role::findByName('waiter', 'web')->givePermissionTo(Permission::findOrCreate('reports.custom', 'web'));
    $manager->givePermissionTo(Permission::findOrCreate('devices.manage', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    phaseFiveDeviceGrantMigration()->up();

    expect(Role::findByName('waiter', 'web')->hasPermissionTo('reports.custom'))->toBeTrue()
        ->and($manager->fresh()?->hasDirectPermission('devices.manage'))->toBeTrue()
        ->and(Permission::query()->where('name', 'devices.manage')->count())->toBe(1);
});

it('is a no-op the second time and on a database that already matches the registry, and does nothing on the way down', function () {
    beforeStepFiveOneA();
    phaseFiveDeviceGrantMigration()->up();
    $once = phaseFiveDeviceGrantSnapshot();
    phaseFiveDeviceGrantMigration()->up();
    expect(phaseFiveDeviceGrantSnapshot())->toBe($once);

    seedRoles();
    $seeded = phaseFiveDeviceGrantSnapshot();
    phaseFiveDeviceGrantMigration()->up();
    expect(phaseFiveDeviceGrantSnapshot())->toBe($seeded);

    phaseFiveDeviceGrantMigration()->down();
    expect(phaseFiveDeviceGrantSnapshot())->toBe($seeded);
});
