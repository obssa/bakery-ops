<?php

declare(strict_types=1);

namespace Database\Factories\Identity;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserStationAssignment>
 */
final class UserStationAssignmentFactory extends Factory
{
    protected $model = UserStationAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'station_id' => Station::factory(),
            'can_count' => false,
            'is_overseer' => false,
        ];
    }

    /** user, station and the row in one branch */
    public function in(Branch $branch): self
    {
        return $this->state(fn (): array => [
            'branch_id' => $branch->id,
            'user_id' => User::factory()->for($branch, 'branch'),
            'station_id' => Station::factory()->for($branch, 'branch'),
        ]);
    }

    public function counter(): self
    {
        return $this->state(fn (): array => ['can_count' => true]);
    }

    public function overseer(): self
    {
        return $this->state(fn (): array => ['is_overseer' => true]);
    }
}
