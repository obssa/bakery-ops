<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The store of a fresh branch by default; ofType(LocationType::Kitchen) for the others, station_id when a test ties
 * the location to a station.
 *
 * @extends Factory<Location>
 */
final class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name_en' => 'Store',
            'name_am' => 'መጋዘን',
            'station_id' => null,
            'type' => LocationType::Store,
        ];
    }

    public function ofType(LocationType $type): self
    {
        $names = [
            LocationType::Store->value => ['Store', 'መጋዘን'],
            LocationType::Kitchen->value => ['Kitchen', 'ኩሽና'],
            LocationType::Bar->value => ['Bar', 'ባር'],
            LocationType::CakeRoom->value => ['Cake room', 'የኬክ ክፍል'],
            LocationType::Bakery->value => ['Bakery', 'ዳቦ ቤት'],
            LocationType::DisplayCase->value => ['Display case', 'ማሳያ'],
            LocationType::CashDrawer->value => ['Cash drawer', 'የገንዘብ መሳቢያ'],
        ];

        return $this->state(fn (): array => [
            'type' => $type,
            'name_en' => $names[$type->value][0],
            'name_am' => $names[$type->value][1],
        ]);
    }
}
