<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where stock is held (domain-model.md, Inventory): stock is per location, so kitchen flour and bakery flour are
 * different piles. type: store | kitchen | bar | cake_room | bakery | display_case | cash_drawer (CHECK constraint, the
 * same list as App\Modules\Inventory\Enums\LocationType). station_id ties a location to the station that works from
 * it (availability for a station's lines is computed at its location; display-case products at the display_case
 * location). Names are bilingual (rule 9) because the admin inventory screen and the KDS stock strip show them.
 * Configuration, not an operational write: audited, soft-deleted, no actor stamp columns (like categories).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name_en', 120);
            $table->string('name_am', 120);
            $table->foreignId('station_id')->nullable()->constrained('stations')->restrictOnDelete();
            $table->string('type', 20);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'type']);
        });

        DB::statement("ALTER TABLE locations ADD CONSTRAINT locations_type_check CHECK (type IN ('store', 'kitchen', 'bar', 'cake_room', 'bakery', 'display_case', 'cash_drawer'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
