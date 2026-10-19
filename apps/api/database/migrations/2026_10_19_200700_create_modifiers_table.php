<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modifiers of a product (domain-model.md, Catalog): "no onion", large size. price_delta is money (may be negative);
 * recipe_delta is plain JSON (not JSONB: MySQL/MariaDB live, ADR 0006): a list of
 * {ingredient_item_id, qty (signed decimal string), unit_id}, converted to base units by Modifiers::for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('name_en', 120);
            $table->string('name_am', 120);
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->json('recipe_delta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifiers');
    }
};
