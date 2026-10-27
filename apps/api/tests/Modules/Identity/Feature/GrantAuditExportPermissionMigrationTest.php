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
 * Step 4.5a (phase-4 plan section 10.6): audit.export (the admin: GET /audit-log/export) reaches the live database through its own grant-only
 * migration, because M2 (2026_10_30_100100_grant_phase_4_permissions) has run there already and a deploy never runs seeders. Same guarantees as
 * every grant: never revokes, creates no role, writes nothing the second time, clears spatie's cache. The start state is "the live database
 * before step 4.5a": the roles and the earlier grants exist (audit.view since Phase 0), audit.export does not (the plan named it for M2, which
 * step 4.2b built without it).
 */

function auditExportGrantMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_08_100200_grant_audit_export_permission.php');

    return $migration;
}

function beforeStepFourFiveA(): void
{
    seedRoles();
    Permission::query()->where('name', 'audit.export')->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** @return array<string, list<array<string, mixed>>> */
function auditExportSnapshot(): array
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

it('has audit.export in the registry for the admin only', function () {
    expect(PermissionRegistry::isPermission('audit.export'))->toBeTrue()
        ->and(PermissionRegistry::rolesFor('audit.export'))->toBe(['admin']);
});

it('creates the permission and grants it to the admin only', function () {
    beforeStepFourFiveA();
    expect(Permission::query()->where('name', 'audit.export')->count())->toBe(0);

    auditExportGrantMigration()->up();

    expect(Permission::query()->where('name', 'audit.export')->where('guard_name', 'web')->count())->toBe(1);
    foreach (PermissionRegistry::ROLES as $role) {
        expect(Role::findByName($role, 'web')->hasPermissionTo('audit.export'))->toBe($role === 'admin', $role);
    }
    $branch = Branch::factory()->create();
    expect(makeUser($branch, 'admin')->fresh()?->can('audit.export'))->toBeTrue('the cache was cleared')
        ->and(makeUser($branch, 'manager')->fresh()?->can('audit.export'))->toBeFalse('the manager reads the log, the admin exports it')
        ->and(makeUser($branch, 'manager')->fresh()?->can('audit.view'))->toBeTrue('audit.view is untouched');
});

it('never revokes: a hand-made grant, a direct permission and a manager given audit.export by hand survive', function () {
    $branch = Branch::factory()->create();
    beforeStepFourFiveA();
    $manager = makeUser($branch, 'manager');
    Role::findByName('waiter', 'web')->givePermissionTo(Permission::findOrCreate('reports.custom', 'web'));
    $manager->givePermissionTo(Permission::findOrCreate('audit.export', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    auditExportGrantMigration()->up();

    expect(Role::findByName('waiter', 'web')->hasPermissionTo('reports.custom'))->toBeTrue()
        ->and($manager->fresh()?->hasDirectPermission('audit.export'))->toBeTrue()
        ->and(Permission::query()->where('name', 'audit.export')->count())->toBe(1);
});

it('is a no-op the second time and on a database that already matches the registry, and does nothing on the way down', function () {
    beforeStepFourFiveA();
    auditExportGrantMigration()->up();
    $once = auditExportSnapshot();
    auditExportGrantMigration()->up();
    expect(auditExportSnapshot())->toBe($once);

    seedRoles();
    $seeded = auditExportSnapshot();
    auditExportGrantMigration()->up();
    expect(auditExportSnapshot())->toBe($seeded);

    auditExportGrantMigration()->down();
    expect(auditExportSnapshot())->toBe($seeded);
});
