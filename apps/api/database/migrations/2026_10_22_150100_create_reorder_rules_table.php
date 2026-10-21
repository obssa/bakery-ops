<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reorder rules (phase-2 plan section 3, migration 14; Inventory, step 2.6a). A rule says: when the available stock of this item
 * at this place is at or below min_qty it is running low (the `low` alert, ADR 0011), and reorder_qty is how much to buy or make
 * then. A rule without a place (location_id NULL, location_key 0) covers the branch: the sum of `available` over its stock
 * places. Configuration, so it soft-deletes and carries the actor stamp (rule 4): created_by_user_id, acting_user_id,
 * shift_instance_id (no foreign key until Shifts exists, Phase 3), actor_station_id, device_id. version counts the changes.
 *
 * - min_qty and reorder_qty are DECIMAL(14,4) in the item's base unit. min_qty may be zero (alert at zero), reorder_qty must be above zero.
 * - notify_roles is a JSON list from the permission registry's roles plus the literal "station" (the people assigned to the
 *   station of the place); the application validates it, the database only keeps it.
 * - One LIVE rule per item and place, guaranteed by the database itself, whatever the code does. MariaDB and MySQL have no
 *   partial index, so a STORED generated column active_key holds 1 while the rule is not soft-deleted and NULL otherwise, under
 *   the UNIQUE index (item_id, location_key, active_key) (NULLs never collide, so any number of deleted rules may stay as
 *   history); PostgreSQL gets the partial unique index reorder_rules_one_live. An inactive rule (is_active = 0) still occupies
 *   its place: it is switched back on, not duplicated. location_key is the location id, or 0 for the branch-wide rule, so the
 *   key can include it (a NULL location_id would make every branch-wide rule distinct), the same device as stock_alert_states.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reorder_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->unsignedBigInteger('location_key')->default(0);
            $table->decimal('min_qty', 14, 4);
            $table->decimal('reorder_qty', 14, 4);
            $table->json('notify_roles');
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('actor_station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'item_id']);
        });

        DB::statement('ALTER TABLE reorder_rules ADD CONSTRAINT reorder_rules_min_qty_check CHECK (min_qty >= 0)');
        DB::statement('ALTER TABLE reorder_rules ADD CONSTRAINT reorder_rules_reorder_qty_check CHECK (reorder_qty > 0)');

        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE reorder_rules ADD COLUMN active_key TINYINT UNSIGNED GENERATED ALWAYS AS '
                .'(CASE WHEN deleted_at IS NULL THEN 1 END) STORED, '
                .'ADD UNIQUE INDEX reorder_rules_one_live (item_id, location_key, active_key)',
            );
        } elseif ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX reorder_rules_one_live ON reorder_rules (item_id, location_key) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reorder_rules');
    }
};
