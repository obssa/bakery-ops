<?php

declare(strict_types=1);

use App\Modules\Identity\Support\GrantRegistryPermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Step 4.4b (phase-4 plan section 10.6): analytics.manage (the admin: POST /analytics/rebuild, from step 4.4d also the weekly summary's test send)
 * joins Identity's PermissionRegistry. The plan named it for M2 (2026_10_30_100100_grant_phase_4_permissions), which step 4.2b built without it
 * and which has run on the live database, so it is not edited; a deploy never runs seeders, so this grant-only migration puts the permission and
 * its default grant there before the route uses it. All the work, and the guarantees (never revokes, creates no role, idempotent, clears
 * spatie's cache), are in GrantRegistryPermissions, which reads the registry: on a database that has the permission already it writes nothing.
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
