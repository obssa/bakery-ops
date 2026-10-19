<?php

declare(strict_types=1);

namespace Database\Factories\Shared;

use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().' branch',
            'timezone' => 'Africa/Addis_Ababa',
            'currency' => 'ETB',
            'settings' => [],
        ];
    }
}
