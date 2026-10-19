<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A unit beyond the standard set the migration inserts (kg g L ml pcs slice tray): a base unit by default.
 *
 * @extends Factory<Unit>
 */
final class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'u'.fake()->unique()->numerify('#####'),
            'name_en' => 'Unit',
            'name_am' => 'መለኪያ',
            'base_unit_id' => null,
            'factor' => '1',
        ];
    }

    /** A unit that converts to the standard base unit $baseCode by $factor (e.g. a 50 kg sack: g, 50000). */
    public function derivedFrom(string $baseCode, string $factor): self
    {
        return $this->state(fn (): array => [
            'base_unit_id' => Unit::query()->where('code', $baseCode)->value('id'),
            'factor' => $factor,
        ]);
    }
}
