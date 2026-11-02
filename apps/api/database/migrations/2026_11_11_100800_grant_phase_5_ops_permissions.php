<?php

declare(strict_types=1);

use App\Modules\Identity\Support\GrantRegistryPermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Step 5.5c, phase-5 plan section 3 M2c and 10.6: system.view (the admin's System page and GET /system/status) joins Identity's
 * PermissionRegistry. A deploy never runs seeders, so this grant-only migration puts it and its default grant in the live database before
 * the route uses it. All the work, and the guarantees (never revokes, creates no role, idempotent, clears spatie's cache), are in
 * GrantRegistryPermissions, which reads the registry: on a database that has it already it writes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        GrantRegistryPermissions::run();
    }

    /**
     * Does nothing, on purpose: rolling back must never take access away or delete a permission that rows elsewhere point at.
     */
    public function down(): void {}
};
