<?php

declare(strict_types=1);

use App\Modules\Identity\Support\GrantRegistryPermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 4 adds permissions to Identity's PermissionRegistry (files.upload and payments.view in step 4.2b; plan section 10.6). A deploy never
 * runs seeders, so this grant-only migration puts them, and their default grants, into the live database before any route uses them.
 * All the work, and the guarantees (never revokes, creates no role, idempotent, clears spatie's cache), are in GrantRegistryPermissions, as
 * in Phase 3's 2026_10_24_100100_grant_phase_3_permissions. It reads the registry, so a later Phase 4 step that adds a permission
 * (payments.verify, analytics.manage, audit.export) adds a new dated migration that calls the same class; this one is not edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        GrantRegistryPermissions::run();
    }

    /**
     * Does nothing, on purpose: the permissions and grants this migration added may be in use by the time somebody rolls
     * it back, and rolling back must never take access away or delete a permission that rows elsewhere point at.
     */
    public function down(): void {}
};
