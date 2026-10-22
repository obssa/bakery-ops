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
 * Step 3.1a (phase-3 plan section 10.6): the three permissions Phase 3 adds to the registry (shifts.view, shifts.manage,
 * shifts.replay) reach the live database through a grant-only migration, because a deploy never runs seeders. Same
 * pattern and guarantees as GrantPhase2PermissionsMigrationTest, through Identity\Support\GrantRegistryPermissions. The
 * start state here is "a database seeded after Phase 2": the roles exist with the Phase 2 grants (shifts.count, .cosign,
 * .approve and .unlock already among them) and the three permissions do not.
 */

const PHASE_3_PERMISSIONS = [
    'shifts.view' => ['admin', 'manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker'],
    'shifts.manage' => ['admin'],
    'shifts.replay' => ['admin', 'manager'],
];

function phase3GrantMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_24_100100_grant_phase_3_permissions.php');

    return $migration;
}

/** A database as it was after Phase 2: seeded from the registry, then the Phase 3 permissions taken away again. */
function afterPhaseTwo(): void
{
    seedRoles();
    Permission::query()->whereIn('name', array_keys(PHASE_3_PERMISSIONS))->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** @return array<string, list<array<string, mixed>>> */
function phase3Snapshot(): array
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
function phase3PermissionsOf(string $role): array
{
    $names = Role::findByName($role, 'web')->permissions()->pluck('name')->all();
    sort($names);

    return array_values($names);
}

it('creates the four permissions and grants them to their default roles, as plan section 10.5 lists', function () {
    afterPhaseTwo();
    expect(Permission::query()->whereIn('name', array_keys(PHASE_3_PERMISSIONS))->count())->toBe(0);

    phase3GrantMigration()->up();

    foreach (PHASE_3_PERMISSIONS as $name => $roles) {
        expect(Permission::query()->where('name', $name)->where('guard_name', 'web')->count())->toBe(1, $name);
        foreach (PermissionRegistry::ROLES as $role) {
            expect(in_array($name, phase3PermissionsOf($role), true))->toBe(in_array($role, $roles, true), "{$role} / {$name}");
        }
    }
    foreach (PermissionRegistry::ROLES as $role) {
        $expected = PermissionRegistry::permissionsFor($role);
        sort($expected);
        expect(phase3PermissionsOf($role))->toBe($expected, $role);
    }
})->group('module:identity', 'feature');

it('never revokes: a permission added by hand to a role, a direct permission and every role assignment survive', function () {
    $branch = Branch::factory()->create();
    seedRoles();
    $waiter = makeUser($branch, 'waiter');
    $chef = makeUser($branch, 'chef');
    Role::findByName('waiter', 'web')->givePermissionTo(Permission::findOrCreate('reports.custom', 'web'));
    $chef->givePermissionTo('inventory.adjust');
    Permission::query()->whereIn('name', array_keys(PHASE_3_PERMISSIONS))->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    phase3GrantMigration()->up();

    expect(phase3PermissionsOf('waiter'))->toContain('reports.custom')
        ->and(phase3PermissionsOf('waiter'))->not->toContain('shifts.view', 'shifts.replay')
        ->and($waiter->fresh()?->hasRole('waiter'))->toBeTrue()
        ->and($chef->fresh()?->hasDirectPermission('inventory.adjust'))->toBeTrue()
        ->and($chef->fresh()?->hasRole('chef'))->toBeTrue()
        ->and($chef->fresh()?->can('shifts.view'))->toBeTrue()
        ->and(DB::table('model_has_permissions')->count())->toBe(1);
})->group('module:identity', 'feature');

it('is a no-op the second time and on a database that already matches the registry', function () {
    afterPhaseTwo();
    Role::findOrCreate('shift_lead', 'web')->givePermissionTo(Permission::findOrCreate('custom.one', 'web'));

    phase3GrantMigration()->up();
    $once = phase3Snapshot();
    phase3GrantMigration()->up();

    expect(phase3Snapshot())->toBe($once);

    seedRoles();
    $seeded = phase3Snapshot();
    phase3GrantMigration()->up();
    expect(phase3Snapshot())->toBe($seeded);
})->group('module:identity', 'feature');

it('works on a database no seeder has run on: it creates the permissions and, with no roles, creates no roles', function () {
    DB::table('permissions')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(Permission::query()->count())->toBe(0)->and(Role::query()->count())->toBe(0);

    phase3GrantMigration()->up();

    expect(Permission::query()->where('guard_name', 'web')->pluck('name')->all())->toEqualCanonicalizing(PermissionRegistry::permissions())
        ->and(Role::query()->count())->toBe(0)
        ->and(DB::table('role_has_permissions')->count())->toBe(0);
})->group('module:identity', 'feature');

it('grants only the roles that exist and leaves other roles and other guards alone', function () {
    afterPhaseTwo();
    Role::query()->where('name', 'bread_baker')->where('guard_name', 'web')->firstOrFail()->delete();
    Role::findOrCreate('admin', 'sanctum')->givePermissionTo(Permission::findOrCreate('catalog.manage', 'sanctum'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    phase3GrantMigration()->up();

    expect(Role::query()->where('name', 'bread_baker')->exists())->toBeFalse()
        ->and(Role::findByName('admin', 'sanctum')->permissions()->pluck('name')->all())->toBe(['catalog.manage'])
        ->and(Permission::query()->where('name', 'shifts.replay')->count())->toBe(1)
        ->and(phase3PermissionsOf('admin'))->toContain('shifts.replay', 'shifts.manage');
})->group('module:identity', 'feature');

it('clears the permission cache so the new grant is seen at once', function () {
    afterPhaseTwo();
    $registrar = app(PermissionRegistrar::class);
    expect($registrar->getPermissions()->pluck('name')->all())->not->toContain('shifts.replay'); // cache now warm

    phase3GrantMigration()->up();

    expect($registrar->getPermissions()->pluck('name')->all())->toContain('shifts.replay');
    $branch = Branch::factory()->create();
    expect(makeUser($branch, 'manager')->fresh()?->can('shifts.replay'))->toBeTrue()
        ->and(makeUser($branch, 'cashier')->fresh()?->can('shifts.replay'))->toBeFalse();
})->group('module:identity', 'feature');

it('does nothing on the way down: what it granted may be in use', function () {
    afterPhaseTwo();
    phase3GrantMigration()->up();
    $after = phase3Snapshot();

    phase3GrantMigration()->down();

    expect(phase3Snapshot())->toBe($after)
        ->and(phase3PermissionsOf('admin'))->toContain('shifts.replay');
})->group('module:identity', 'feature');
