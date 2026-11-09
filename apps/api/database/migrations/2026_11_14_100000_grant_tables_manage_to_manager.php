<?php

declare(strict_types=1);

use App\Modules\Identity\Support\GrantRegistryPermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 6 follow-up (Admin > Tables; Ramin, 2026-11-09: admin and manager manage the tables): tables.manage, the admin's only since Phase 1,
 * is granted to the manager as well in Identity's PermissionRegistry. A deploy never runs seeders, so this grant-only migration puts the
 * manager's new grant in the live database. All the work, and the guarantees (never revokes, creates no role, idempotent, clears spatie's
 * cache), are in GrantRegistryPermissions, which reads the registry: on a database that has the grant already it writes nothing.
 *
 * The grant is one of PermissionRegistry's LATER_GRANTS, keyed to this migration's timestamp, which it passes: it is the only grant
 * migration that makes it, so the earlier ones (the 1.3e grant among them) still do exactly what they did on the live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        GrantRegistryPermissions::run('2026_11_14_100000');
    }

    /**
     * Does nothing, on purpose: rolling back must never take access away from a manager who is using it.
     */
    public function down(): void {}
};
