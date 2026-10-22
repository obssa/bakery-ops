<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stations a shift template applies to (phase-3 plan section 3, migration 4; Shifts, step 3.2a). One row per pair,
 * unique on (template_id, station_id) even among soft-deleted rows: a removed pair is restored, not duplicated, the way
 * user_station_assignments is. No actor stamp of its own: the template's audit row carries it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_template_stations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('template_id')->constrained('shift_templates')->restrictOnDelete();
            $table->foreignId('station_id')->constrained('stations')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['template_id', 'station_id'], 'shift_template_stations_pair_unique');
            $table->index('station_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_template_stations');
    }
};
