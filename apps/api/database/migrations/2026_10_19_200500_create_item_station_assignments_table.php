<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an item's order lines route (domain-model.md, Catalog): latte -> bar, sandwich -> kitchen; none for a
 * display-case product. One row per (item, station) for the row's whole life: PUT /items/{item}/stations
 * soft-deletes the rows that go and restores the ones that come back (rule 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_station_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->foreignId('station_id')->constrained('stations')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['item_id', 'station_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_station_assignments');
    }
};
