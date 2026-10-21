<?php

declare(strict_types=1);

use App\Modules\Identity\Support\GrantRegistryPermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 2 adds four permissions to Identity's PermissionRegistry (production.view, production.plan, notifications.view,
 * costs.view; plan section 10.5). A deploy never runs seeders, so this grant-only migration puts them, and their default
 * grants, into the live database before any route uses them. All the work, and the guarantees (never revokes, creates
 * no role, idempotent, clears spatie's cache), are in GrantRegistryPermissions; the Phase 1 migration of the same kind
 * (2026_10_21_120100_grant_new_permissions) is left as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        GrantRegistryPermissions::run();
    }

    /**
     * Does nothing, on purpose: the permissions and grants this migration added may be in use by the time somebody rolls
     * it back (a user may hold the role, an admin may have granted the permission to someone), and rolling back must
     * never take access away or delete a permission that rows elsewhere point at. Removing one is a deliberate act.
     */
    public function down(): void {}
};
