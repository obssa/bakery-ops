<?php

declare(strict_types=1);

use App\Modules\Identity\Support\GrantRegistryPermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Step 5.1a, phase-5 plan section 3 M2a and 10.6: devices.manage (the admin: GET and POST /devices, rename and revoke, split out of
 * people.manage, which the admin keeps, so nobody loses access) and devices.self (every role: GET /devices/this, "is this tablet
 * enrolled?") join Identity's PermissionRegistry. A deploy never runs seeders, so this grant-only migration puts them and their default
 * grants in the live database before the routes use them. All the work, and the guarantees (never revokes, creates no role, idempotent,
 * clears spatie's cache), are in GrantRegistryPermissions, which reads the registry: on a database that has them already it writes nothing.
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
