<?php

declare(strict_types=1);

use App\Modules\Identity\Support\GrantRegistryPermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 3 adds three permissions to Identity's PermissionRegistry (shifts.view, shifts.manage, shifts.replay; plan
 * section 10.6). A deploy never runs seeders, so this grant-only migration puts them, and their default grants, into
 * the live database before any route uses them (the first is step 3.2b). All the work, and the guarantees (never
 * revokes, creates no role, idempotent, clears spatie's cache), are in GrantRegistryPermissions, as in Phase 2's
 * 2026_10_22_100100_grant_phase_2_permissions.
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
