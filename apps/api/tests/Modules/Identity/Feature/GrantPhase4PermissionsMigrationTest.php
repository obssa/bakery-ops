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
 * Step 4.2b (phase-4 plan section 10.6, M2): files.upload and payments.view reach the live database through a grant-only migration, because a
 * deploy never runs seeders. Same guarantees as the Phase 2 and 3 grants: never revokes, creates no role, writes nothing the second time,
 * clears spatie's cache. The start state is "a database seeded after Phase 3": the roles exist and the Phase 4 permissions do not.
 */

const PHASE_4_FILE_PERMISSIONS = [
    'files.upload' => ['admin', 'manager', 'cashier'],
    'payments.view' => ['admin', 'manager', 'cashier'],
];

function phase4GrantMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_30_100100_grant_phase_4_permissions.php');

    return $migration;
}

function afterPhaseThree(): void
{
    seedRoles();
    Permission::query()->whereIn('name', array_keys(PHASE_4_FILE_PERMISSIONS))->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** @return array<string, list<array<string, mixed>>> */
function phase4Snapshot(): array
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
function phase4PermissionsOf(string $role): array
{
    $names = Role::findByName($role, 'web')->permissions()->pluck('name')->all();
    sort($names);

    return array_values($names);
}

it('has both permissions in the registry for the default roles of plan section 10.6', function () {
    foreach (PHASE_4_FILE_PERMISSIONS as $name => $roles) {
        expect(PermissionRegistry::isPermission($name))->toBeTrue($name)
            ->and(PermissionRegistry::rolesFor($name))->toEqualCanonicalizing($roles);
    }
})->group('module:identity', 'feature');

it('creates the permissions and grants them to their default roles', function () {
    afterPhaseThree();
    expect(Permission::query()->whereIn('name', array_keys(PHASE_4_FILE_PERMISSIONS))->count())->toBe(0);

    phase4GrantMigration()->up();

    foreach (PHASE_4_FILE_PERMISSIONS as $name => $roles) {
        expect(Permission::query()->where('name', $name)->where('guard_name', 'web')->count())->toBe(1, $name);
        foreach (PermissionRegistry::ROLES as $role) {
            expect(in_array($name, phase4PermissionsOf($role), true))->toBe(in_array($role, $roles, true), "{$role} / {$name}");
        }
    }
})->group('module:identity', 'feature');

it('never revokes: a hand-added role permission, a direct permission and every role assignment survive', function () {
    $branch = Branch::factory()->create();
    seedRoles();
    $waiter = makeUser($branch, 'waiter');
    $chef = makeUser($branch, 'chef');
    Role::findByName('waiter', 'web')->givePermissionTo(Permission::findOrCreate('reports.custom', 'web'));
    $chef->givePermissionTo('inventory.adjust');
    Permission::query()->whereIn('name', array_keys(PHASE_4_FILE_PERMISSIONS))->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    phase4GrantMigration()->up();

    expect(phase4PermissionsOf('waiter'))->toContain('reports.custom')->not->toContain('files.upload', 'payments.view')
        ->and($waiter->fresh()?->hasRole('waiter'))->toBeTrue()
        ->and($chef->fresh()?->hasDirectPermission('inventory.adjust'))->toBeTrue()
        ->and($chef->fresh()?->can('files.upload'))->toBeFalse()
        ->and(DB::table('model_has_permissions')->count())->toBe(1);
})->group('module:identity', 'feature');

it('is a no-op the second time and on a database that already matches the registry', function () {
    afterPhaseThree();
    Role::findOrCreate('shift_lead', 'web')->givePermissionTo(Permission::findOrCreate('custom.one', 'web'));

    phase4GrantMigration()->up();
    $once = phase4Snapshot();
    phase4GrantMigration()->up();

    expect(phase4Snapshot())->toBe($once);

    seedRoles();
    $seeded = phase4Snapshot();
    phase4GrantMigration()->up();
    expect(phase4Snapshot())->toBe($seeded);
})->group('module:identity', 'feature');

it('creates no roles on a database no seeder has run on', function () {
    DB::table('permissions')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    phase4GrantMigration()->up();

    expect(Permission::query()->where('guard_name', 'web')->pluck('name')->all())->toEqualCanonicalizing(PermissionRegistry::permissions())
        ->and(Role::query()->count())->toBe(0)
        ->and(DB::table('role_has_permissions')->count())->toBe(0);
})->group('module:identity', 'feature');

it('clears the permission cache so the new grant is seen at once', function () {
    afterPhaseThree();
    $registrar = app(PermissionRegistrar::class);
    expect($registrar->getPermissions()->pluck('name')->all())->not->toContain('files.upload');

    phase4GrantMigration()->up();

    expect($registrar->getPermissions()->pluck('name')->all())->toContain('files.upload');
    $branch = Branch::factory()->create();
    expect(makeUser($branch, 'cashier')->fresh()?->can('files.upload'))->toBeTrue()
        ->and(makeUser($branch, 'waiter')->fresh()?->can('files.upload'))->toBeFalse();
})->group('module:identity', 'feature');

it('does nothing on the way down', function () {
    afterPhaseThree();
    phase4GrantMigration()->up();
    $after = phase4Snapshot();

    phase4GrantMigration()->down();

    expect(phase4Snapshot())->toBe($after);
})->group('module:identity', 'feature');
