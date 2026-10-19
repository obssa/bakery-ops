<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Categories for both inventories (domain-model.md, Catalog): Flour & grains, Dairy, Packaging, Breads, Cakes, Hot
 * drinks ... A category holds one kind of item (ingredient | product, CHECK constraint); a parent is a category of
 * the same kind and branch. Soft deletes only (rule 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('name_en', 120);
            $table->string('name_am', 120);
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'kind', 'sort']);
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_kind_check CHECK (kind IN ('ingredient', 'product'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
