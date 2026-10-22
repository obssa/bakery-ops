<?php

declare(strict_types=1);

namespace Database\Factories\Shifts;

use App\Modules\Shifts\Models\VarianceReason;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An extra reason of a branch (every branch already has the five defaults, so the codes here are numbered).
 *
 * @extends Factory<VarianceReason>
 */
final class VarianceReasonFactory extends Factory
{
    protected $model = VarianceReason::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'branch_id' => Branch::factory(),
            'code' => "reason_{$n}",
            'name_en' => "Reason {$n}",
            'name_am' => "ምክንያት {$n}",
            'requires_note' => false,
            'is_active' => true,
            'sort' => 100,
            'created_by_user_id' => null,
            'version' => 1,
        ];
    }

    public function requiringNote(): self
    {
        return $this->state(fn (): array => ['requires_note' => true]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
