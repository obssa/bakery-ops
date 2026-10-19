<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/**
 * The units every database starts with (inserted by the create_units_table migration; CatalogSchemaTest keeps the
 * two identical): three base units (g, ml, pcs) and the display units that convert to them. Plain data so unit
 * tests can exercise the conversions without a database.
 */
final class StandardUnits
{
    /** @var array<string, array{name_en: string, name_am: string, base: string|null, factor: string}> */
    public const array ALL = [
        'g' => ['name_en' => 'Gram', 'name_am' => 'ግራም', 'base' => null, 'factor' => '1.0000'],
        'ml' => ['name_en' => 'Millilitre', 'name_am' => 'ሚሊ ሊትር', 'base' => null, 'factor' => '1.0000'],
        'pcs' => ['name_en' => 'Piece', 'name_am' => 'ፍሬ', 'base' => null, 'factor' => '1.0000'],
        'kg' => ['name_en' => 'Kilogram', 'name_am' => 'ኪሎ ግራም', 'base' => 'g', 'factor' => '1000.0000'],
        'L' => ['name_en' => 'Litre', 'name_am' => 'ሊትር', 'base' => 'ml', 'factor' => '1000.0000'],
        'slice' => ['name_en' => 'Slice', 'name_am' => 'ቁራጭ', 'base' => 'pcs', 'factor' => '1.0000'],
        'tray' => ['name_en' => 'Tray', 'name_am' => 'ትሪ', 'base' => 'pcs', 'factor' => '12.0000'],
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }
}
