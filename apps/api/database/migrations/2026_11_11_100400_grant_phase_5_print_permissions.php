<?php

declare(strict_types=1);

use App\Modules\Identity\Support\GrantRegistryPermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Step 5.3a, phase-5 plan section 3 M2b and 10.6: print.create (POST /orders/{order}/print) and print.view (GET /print-jobs and GET
 * /orders/{order}/receipt.escpos), both for the admin, the manager and the cashier, join Identity's PermissionRegistry. A deploy never runs
 * seeders, so this grant-only migration puts them and their default grants in the live database before the routes use them. The print
 * bridge's own routes need no permission: they are authorised by the bridge's device token. All the work, and the guarantees (never
 * revokes, creates no role, idempotent, clears spatie's cache), are in GrantRegistryPermissions, which reads the registry: on a database
 * that has them already it writes nothing.
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
