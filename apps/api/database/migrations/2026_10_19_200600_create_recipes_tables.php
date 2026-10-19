<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recipes (domain-model.md, Catalog; schema in Phase 0 because availability and reservations need activeFor, version
 * publishing and cost roll-up arrive in Phase 2). A recipe yields yield_qty of the product in yield_unit; each line
 * is per yield, in any unit that converts to the ingredient's base unit; waste_pct is a percentage (0 <= w < 100),
 * not money. One active version per product; old versions are kept. Steps are the optional bilingual method shown on
 * the production card. Replacing version 1 soft-deletes its old lines and steps (rule 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('product_item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('yield_qty', 14, 4);
            $table->foreignId('yield_unit_id')->constrained('units')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['product_item_id', 'version']);
            $table->index(['product_item_id', 'is_active']);
        });

        Schema::create('recipe_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('recipe_id')->constrained('recipes')->restrictOnDelete();
            $table->foreignId('ingredient_item_id')->constrained('items')->restrictOnDelete();
            $table->decimal('qty', 14, 4);
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->boolean('is_optional')->default(false);
            $table->decimal('waste_pct', 5, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('recipe_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('recipe_id')->constrained('recipes')->restrictOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->text('text_en');
            $table->text('text_am');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['recipe_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_steps');
        Schema::dropIfExists('recipe_lines');
        Schema::dropIfExists('recipes');
    }
};
