<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Contracts\PermissionRegistry;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The section 7 matrix into spatie's tables (guard `web`): every permission name, the eight roles, each role's
 * default permissions. Idempotent (findOrCreate + sync), so every phase seeder and every test can call it; a
 * permission removed from PermissionRegistry stays in the table for history and is no longer granted by any role.
 */
final class RolesSeeder extends Seeder
{
    public const string GUARD = 'web';

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (PermissionRegistry::permissions() as $name) {
            Permission::findOrCreate($name, self::GUARD);
        }
        foreach (PermissionRegistry::ROLES as $role) {
            Role::findOrCreate($role, self::GUARD)->syncPermissions(PermissionRegistry::permissionsFor($role));
        }

        $registrar->forgetCachedPermissions();
    }
}
