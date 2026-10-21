<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Episodes of "this item ran out (out) or ran low (low) at this place" (phase-2 plan section 3, migration 2; ADR 0011).
 * Inventory's balance evaluation opens a row when available falls to the level and closes it when it recovers; the
 * opening is what announces StockOut once, so the table is what makes the alert fire once per episode however often the
 * balance is evaluated, redelivered or raced.
 *
 * At most one OPEN episode per item, place and type, enforced by the database itself on both engines with no partial
 * index: is_active is 1 while the episode is open and NULL once it is cleared, NULLs are distinct in a unique index, so
 * the unique key (item_id, location_key, type, is_active) allows any number of cleared episodes and one open one.
 * location_key is the location id, or 0 for a branch-wide rule (step 2.6), because a NULL location_id would make every
 * branch-wide row distinct. Episodes are history: no soft delete, and the only columns that change are cleared_at and
 * is_active when the episode closes. caused_by_user_id is the person whose movement started it (the evaluator writes it,
 * restored from the movement's actor stamp), so there are no other stamp columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alert_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->unsignedBigInteger('location_key')->default(0);
            $table->string('type', 8);
            $table->unsignedTinyInteger('is_active')->nullable();
            $table->dateTime('active_since', 6);
            $table->dateTime('cleared_at', 6)->nullable();
            $table->decimal('available_at_start', 14, 4);
            $table->decimal('threshold_qty', 14, 4)->nullable();
            $table->foreignId('caused_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'location_key', 'type', 'is_active'], 'stock_alert_states_one_open');
            $table->index(['branch_id', 'is_active']);
        });

        DB::statement("ALTER TABLE stock_alert_states ADD CONSTRAINT stock_alert_states_type_check CHECK (type IN ('low', 'out'))");
        DB::statement('ALTER TABLE stock_alert_states ADD CONSTRAINT stock_alert_states_is_active_check CHECK (is_active IS NULL OR is_active = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alert_states');
    }
};
