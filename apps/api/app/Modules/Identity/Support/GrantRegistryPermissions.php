<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Contracts\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings an existing database up to Identity's PermissionRegistry, grant only (phase-2 plan section 10.5; the logic of
 * step 1.3e's migration, shared so that every phase's grant migration is a thin caller and none carries a copy).
 *
 * RolesSeeder writes the matrix into spatie's tables, but a deploy never runs seeders, so a permission added to the
 * registry after the live database was seeded would exist in code and not in the database. This creates every registry
 * permission the `web` guard (the guard RolesSeeder uses) lacks and attaches each registry permission to the default
 * roles that exist and do not hold it yet. It
 *   - never revokes, deletes, renames or updates anything: a permission an admin handed to a role by hand, a
 *     permission removed from the registry, the roles of users and users' direct permissions are left exactly as they
 *     are, and no role is created (a database without roles gets the permissions; RolesSeeder grants them later);
 *   - is idempotent: a second run finds everything in place and writes nothing;
 *   - needs no seeder to have run, and reads the registry rather than a copy of it;
 *   - clears spatie's permission cache, so the new grant is seen at once;
 *   - works on MariaDB 10.11 and PostgreSQL 16: plain inserts through the query builder, `insertOrIgnore` for the
 *     role grants (composite primary key).
 * It talks to the tables, not to the Spatie models, so a later change to the models cannot change what a migration
 * that calls it did.
 */
final class GrantRegistryPermissions
{
    private const string GUARD = 'web';

    public static function run(): void
    {
        /** @var array{permissions: string, roles: string, role_has_permissions: string} $tables */
        $tables = config('permission.table_names');
        if (! Schema::hasTable($tables['permissions']) || ! Schema::hasTable($tables['roles']) || ! Schema::hasTable($tables['role_has_permissions'])) {
            return;
        }

        DB::transaction(function () use ($tables): void {
            $now = now();

            /** @var array<string, int> $permissionIds name => id */
            $permissionIds = DB::table($tables['permissions'])->where('guard_name', self::GUARD)
                ->pluck('id', 'name')->map(static fn (mixed $id): int => (int) $id)->all();
            foreach (PermissionRegistry::permissions() as $name) {
                if (! isset($permissionIds[$name])) {
                    $permissionIds[$name] = (int) DB::table($tables['permissions'])->insertGetId([
                        'name' => $name,
                        'guard_name' => self::GUARD,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $roleIds = DB::table($tables['roles'])->where('guard_name', self::GUARD)->whereIn('name', PermissionRegistry::ROLES)
                ->pluck('id', 'name')->map(static fn (mixed $id): int => (int) $id)->all();
            foreach ($roleIds as $role => $roleId) {
                $held = DB::table($tables['role_has_permissions'])->where('role_id', $roleId)->pluck('permission_id')
                    ->map(static fn (mixed $id): int => (int) $id)->all();
                foreach (PermissionRegistry::permissionsFor($role) as $name) {
                    if (! in_array($permissionIds[$name], $held, true)) {
                        DB::table($tables['role_has_permissions'])->insertOrIgnore(['permission_id' => $permissionIds[$name], 'role_id' => $roleId]);
                    }
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
