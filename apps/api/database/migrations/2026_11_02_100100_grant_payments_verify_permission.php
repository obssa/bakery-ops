<?php

declare(strict_types=1);

use App\Modules\Identity\Support\GrantRegistryPermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Step 4.3c (phase-4 plan section 10.6): payments.verify (admin, manager: verify and dispute payments against the bank's statement; from step 4.3e
 * also the reconciliation and the export) joins Identity's PermissionRegistry. M2 (2026_10_30_100100_grant_phase_4_permissions) has run on the live
 * database already and is not edited, and a deploy never runs seeders, so this grant-only migration puts the new permission and its default grants
 * there before any route uses it. All the work, and the guarantees (never revokes, creates no role, idempotent, clears spatie's cache), are in
 * GrantRegistryPermissions, which reads the registry: on a database that has the permission already it writes nothing.
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
