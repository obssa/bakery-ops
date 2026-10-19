<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price history (domain-model.md, Catalog): never overwritten. A new price closes the open row (effective_to = the
 * new effective_from) and opens its own; Prices::at reads effective_from inclusive, effective_to exclusive. The only
 * update a row ever sees is that closing, so there is no deleted_at. DATETIME in UTC rather than TIMESTAMP: no
 * implicit ON UPDATE on older MySQL/MariaDB defaults and no 2038 ceiling on a scheduled price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('price', 12, 2);
            $table->char('currency', 3)->default('ETB');
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->timestamps();

            $table->index(['item_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_prices');
    }
};
