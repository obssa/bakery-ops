<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers (phase-2 plan section 3, migration 3; Inventory, step 2.2a): who a purchase is bought from. Edited as
 * configuration, so it soft-deletes and carries the actor stamp (rule 4): created_by_user_id, acting_user_id,
 * shift_instance_id (no foreign key until Shifts exists, Phase 3), actor_station_id, device_id.
 *
 * A supplier's name is a proper noun, so there is no name_en / name_am pair (the same reason a user's name has none); the
 * screens show it as typed. phone and tin are as typed too (the tax identification number of an Ethiopian trader).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('phone', 32)->nullable();
            $table->string('tin', 32)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('acting_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('shift_instance_id')->nullable();
            $table->foreignId('actor_station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
