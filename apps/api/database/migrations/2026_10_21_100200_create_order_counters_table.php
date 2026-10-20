<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily order numbers per branch (domain-model.md, Orders; docs/phases/phase-1-plan.md section 3, migration 2): the
 * short numbers the till and the kitchen call out restart every business day. business_date is the branch's local
 * date (Africa/Addis_Ababa by default); SendOrder locks the row of the day FOR UPDATE, increments last_no and stamps
 * the order with it, so two sends never take the same number. One row per (branch, day), updated in place and never
 * deleted: no created_at, no soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('business_date');
            $table->unsignedInteger('last_no')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['branch_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_counters');
    }
};
