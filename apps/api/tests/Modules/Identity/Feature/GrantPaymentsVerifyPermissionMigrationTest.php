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
 * Step 4.3c (phase-4 plan section 10.6): payments.verify (admin, manager: verify and dispute payments, later the reconciliation and the export)
 * reaches the live database through its own grant-only migration, because M2 (2026_10_30_100100_grant_phase_4_permissions) has run there already
 * and a deploy never runs seeders. Same guarantees as every grant: never revokes, creates no role, writes nothing the second time, clears spatie's
 * cache. The start state is "the live database after step 4.3b": the roles and M2's permissions exist, payments.verify does not.
 */

function paymentsVerifyGrantMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_11_02_100100_grant_payments_verify_permission.php');

    return $migration;
}

function afterStepFourThreeB(): void
{
    seedRoles();
    Permission::query()->where('name', 'payments.verify')->get()->each->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** @return array<string, list<array<string, mixed>>> */
function paymentsVerifySnapshot(): array
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

it('has payments.verify in the registry for the admin and the manager', function () {
    expect(PermissionRegistry::isPermission('payments.verify'))->toBeTrue()
        ->and(PermissionRegistry::rolesFor('payments.verify'))->toEqualCanonicalizing(['admin', 'manager']);
});

it('creates the permission and grants it to the admin and the manager only', function () {
    afterStepFourThreeB();
    expect(Permission::query()->where('name', 'payments.verify')->count())->toBe(0);

    paymentsVerifyGrantMigration()->up();

    expect(Permission::query()->where('name', 'payments.verify')->where('guard_name', 'web')->count())->toBe(1);
    foreach (PermissionRegistry::ROLES as $role) {
        expect(Role::findByName($role, 'web')->hasPermissionTo('payments.verify'))->toBe(in_array($role, ['admin', 'manager'], true), $role);
    }
    $branch = Branch::factory()->create();
    expect(makeUser($branch, 'manager')->fresh()?->can('payments.verify'))->toBeTrue('the cache was cleared')
        ->and(makeUser($branch, 'cashier')->fresh()?->can('payments.verify'))->toBeFalse();
});

it('never revokes: a hand-made grant, a direct permission and a cashier given payments.verify by hand survive', function () {
    $branch = Branch::factory()->create();
    afterStepFourThreeB();
    $cashier = makeUser($branch, 'cashier');
    Role::findByName('waiter', 'web')->givePermissionTo(Permission::findOrCreate('reports.custom', 'web'));
    $cashier->givePermissionTo(Permission::findOrCreate('payments.verify', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    paymentsVerifyGrantMigration()->up();

    expect(Role::findByName('waiter', 'web')->hasPermissionTo('reports.custom'))->toBeTrue()
        ->and($cashier->fresh()?->hasDirectPermission('payments.verify'))->toBeTrue()
        ->and(Permission::query()->where('name', 'payments.verify')->count())->toBe(1);
});

it('is a no-op the second time and on a database that already matches the registry, and does nothing on the way down', function () {
    afterStepFourThreeB();
    paymentsVerifyGrantMigration()->up();
    $once = paymentsVerifySnapshot();
    paymentsVerifyGrantMigration()->up();
    expect(paymentsVerifySnapshot())->toBe($once);

    seedRoles();
    $seeded = paymentsVerifySnapshot();
    paymentsVerifyGrantMigration()->up();
    expect(paymentsVerifySnapshot())->toBe($seeded);

    paymentsVerifyGrantMigration()->down();
    expect(paymentsVerifySnapshot())->toBe($seeded);
});
