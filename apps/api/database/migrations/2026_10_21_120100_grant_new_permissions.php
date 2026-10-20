<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings an existing database up to Identity's PermissionRegistry, grant only (phase-1 plan section 6 item 3, 1.3e).
 *
 * RolesSeeder writes the matrix into spatie's tables, but a deploy never runs seeders, so a permission added to the
 * registry after the live database was seeded (Phase 1: tables.manage) would exist in code and not in the database.
 * This migration creates every registry permission the `web` guard (the guard RolesSeeder uses) lacks and attaches
 * each registry permission to the default roles that exist and do not hold it yet. It
 *   - never revokes, deletes, renames or updates anything: a permission an admin handed to a role by hand, a
 *     permission removed from the registry, the roles of users and users' direct permissions are left exactly as they
 *     are, and no role is created (a database without roles gets the permissions; RolesSeeder grants them later);
 *   - is idempotent: a second run finds everything in place and writes nothing;
 *   - needs no seeder to have run, and reads the registry rather than a copy of it;
 *   - clears spatie's permission cache, so the new grant is seen at once;
 *   - works on MariaDB 10.11 and PostgreSQL 16: plain inserts through the query builder, `insertOrIgnore` for the
 *     role grants (composite primary key).
 * It talks to the tables, not to the Spatie models, so a later change to the models cannot change what this migration
 * did. A deliberate removal of a default grant by hand is not preserved (the grant is put back): the registry is the
 * default, and the application has no screen that edits a role.
 */
return new class extends Migration
{
    private const string GUARD = 'web';

    public function up(): void
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

    /**
     * Does nothing, on purpose: the permissions and grants this migration added may be in use by the time somebody rolls
     * it back (a user may hold the role, an admin may have granted the permission to someone), and rolling back must
     * never take access away or delete a permission that rows elsewhere point at. Removing one is a deliberate act.
     */
    public function down(): void {}
};
