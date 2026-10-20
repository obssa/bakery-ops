<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The modifiers of an order line as they were when the line was written (domain-model.md, Orders;
 * docs/phases/phase-1-plan.md section 3, migration 5): the bilingual name, the price delta per unit (DECIMAL(12,2))
 * and the recipe delta per unit, `[{ingredient_item_id, qty, unit}]` in base units with qty a decimal string, so a
 * later edit of the Catalog modifier never changes a line already sold. Written once with the line: created_at only,
 * no update, no soft delete (the line is never deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_line_modifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('order_line_id')->constrained('order_lines')->restrictOnDelete();
            $table->foreignId('modifier_id')->constrained('modifiers')->restrictOnDelete();
            $table->string('name_en', 120);
            $table->string('name_am', 120);
            $table->decimal('price_delta_snapshot', 12, 2);
            $table->json('recipe_delta_snapshot');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_line_modifiers');
    }
};
