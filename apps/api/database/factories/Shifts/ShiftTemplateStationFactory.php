<?php

declare(strict_types=1);

namespace Database\Factories\Shifts;

use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Models\ShiftTemplateStation;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A fresh Day template paired with a fresh till station of the same branch. Pass template_id and station_id to pair existing ones.
 *
 * @extends Factory<ShiftTemplateStation>
 */
final class ShiftTemplateStationFactory extends Factory
{
    protected $model = ShiftTemplateStation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'template_id' => fn (array $attributes): int => ShiftTemplate::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'station_id' => fn (array $attributes): int => Station::factory()->create(['branch_id' => $attributes['branch_id']])->id,
        ];
    }
}
