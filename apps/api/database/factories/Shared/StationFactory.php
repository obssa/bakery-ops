<?php

declare(strict_types=1);

namespace Database\Factories\Shared;

use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Station>
 */
final class StationFactory extends Factory
{
    protected $model = Station::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = StationCode::Till;

        return [
            'branch_id' => Branch::factory(),
            'code' => $code,
            'name_en' => $code->defaultName()->en,
            'name_am' => $code->defaultName()->am,
            'requires_overseer' => false,
            'sort' => 0,
        ];
    }

    /** One station per code per branch: pick the code explicitly when a test needs more than the till. */
    public function forCode(StationCode $code): self
    {
        $sort = array_search($code, StationCode::cases(), true);

        return $this->state(fn (): array => [
            'code' => $code,
            'name_en' => $code->defaultName()->en,
            'name_am' => $code->defaultName()->am,
            'sort' => $sort === false ? 0 : $sort,
        ]);
    }
}
