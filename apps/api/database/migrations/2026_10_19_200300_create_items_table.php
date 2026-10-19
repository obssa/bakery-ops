<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Both inventories in one table (rule 2, domain-model.md, Catalog). kind: ingredient | product; fulfilment decides
 * how availability is computed (to_order: from the recipe's ingredients; to_stock and bought_in: the item's own
 * on_hand) and whether a stock-out asks for production. unit_id is the item's stock unit; its base unit is what every
 * stock movement of the item is written in, so an item's unit may only change within the same base (PATCH enforces
 * it). production_station_id names the station that makes a display-case product (cake -> cake_room).
 *
 * Actor stamp (rule 4): who created the row, the short-code actor, shift, station and device. shift_instance_id has
 * no foreign key until Shifts exists (Phase 3, dependency-graph.md cycle 1). sku is unique per branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('fulfilment', 20);
            $table->string('name_en', 160);
            $table->string('name_am', 160);
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('sku', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('is_new_until')->nullable();
            $table->decimal('default_batch_qty', 14, 4)->nullable();
            $table->foreignId('production_station_id')->nullable()->constrained('stations')->restrictOnDelete();

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'sku']);
            $table->index(['branch_id', 'kind', 'is_active']);
        });

        DB::statement("ALTER TABLE items ADD CONSTRAINT items_kind_check CHECK (kind IN ('ingredient', 'product'))");
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_fulfilment_check CHECK (fulfilment IN ('to_order', 'to_stock', 'bought_in'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
