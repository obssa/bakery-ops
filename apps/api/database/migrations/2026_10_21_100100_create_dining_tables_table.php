<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables of the dining room (domain-model.md, Orders; docs/phases/phase-1-plan.md section 3, migration 1): the
 * pad's table grid. code is what the waiter taps and what an order snapshots (orders.table_code), unique per branch;
 * the state of a table (free, ordering, waiting, served, bill) is computed from its open orders, never stored.
 * Configuration the admin edits (tables.manage), so it is soft-deleted (rule 4) and carries who created it; the names
 * are bilingual (rule 9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('code', 16);
            $table->string('name_en', 120);
            $table->string('name_am', 120);
            $table->unsignedTinyInteger('seats')->nullable();
            $table->smallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_tables');
    }
};
