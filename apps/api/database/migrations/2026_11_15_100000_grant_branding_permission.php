<?php

declare(strict_types=1);

use App\Modules\Identity\Support\GrantRegistryPermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 6 follow-up (the bakery's own brand, Ramin 2026-11-10): branding.manage (PUT /brand, POST and DELETE /brand/logo: the business name
 * and logo the app shows) joins Identity's PermissionRegistry, the admin's alone. A deploy never runs seeders, so this grant-only migration
 * puts it and its default grant in the live database before the routes use it. All the work, and the guarantees (never revokes, creates no
 * role, idempotent, clears spatie's cache), are in GrantRegistryPermissions, which reads the registry.
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
