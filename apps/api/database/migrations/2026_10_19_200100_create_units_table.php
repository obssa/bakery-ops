<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units of measure (domain-model.md, Catalog): every quantity is stored in the item's base unit; a display unit
 * converts to its base by `factor` (1 kg = 1000 g). Global reference data, so no branch_id (BRANCH_ALLOWLIST).
 * A base unit has no base_unit_id and factor 1; a derived unit points at a base unit, never at another derived one
 * (POST /units enforces it). The standard set is inserted here, so every database (tests included) starts with it;
 * App\Modules\Catalog\Support\StandardUnits mirrors this list and tests/Modules/Catalog/CatalogSchemaTest.php keeps
 * the two identical. `tray` is 12 pieces by default; an admin adds item-specific trays (tray_24) through POST /units.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string, 2: string|null, 3: string}> code => [name_en, name_am, base code, factor] */
    private const array UNITS = [
        'g' => ['Gram', 'ግራም', null, '1.0000'],
        'ml' => ['Millilitre', 'ሚሊ ሊትር', null, '1.0000'],
        'pcs' => ['Piece', 'ፍሬ', null, '1.0000'],
        'kg' => ['Kilogram', 'ኪሎ ግራም', 'g', '1000.0000'],
        'L' => ['Litre', 'ሊትር', 'ml', '1000.0000'],
        'slice' => ['Slice', 'ቁራጭ', 'pcs', '1.0000'],
        'tray' => ['Tray', 'ትሪ', 'pcs', '12.0000'],
    ];

    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name_en', 60);
            $table->string('name_am', 60);
            $table->foreignId('base_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('factor', 14, 4)->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE units ADD CONSTRAINT units_factor_positive CHECK (factor > 0)');

        $now = now();
        $ids = [];
        foreach (self::UNITS as $code => [$en, $am, $base, $factor]) {
            $ids[$code] = DB::table('units')->insertGetId([
                'code' => $code,
                'name_en' => $en,
                'name_am' => $am,
                'base_unit_id' => $base === null ? null : ($ids[$base] ?? null),
                'factor' => $factor,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
