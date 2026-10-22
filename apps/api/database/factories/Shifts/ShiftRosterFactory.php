<?php

declare(strict_types=1);

namespace Database\Factories\Shifts;

use App\Modules\Identity\Models\User;
use App\Modules\Shifts\Enums\RosterSource;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\ShiftRoster;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Someone who joined a fresh shift of the branch a moment ago and is on duty. scheduled() is only on the schedule (never
 * joined); left() is on the roster as history.
 *
 * @extends Factory<ShiftRoster>
 */
final class ShiftRosterFactory extends Factory
{
    protected $model = ShiftRoster::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'shift_instance_id' => fn (array $attributes): int => ShiftInstance::factory()->open()->create(['branch_id' => $attributes['branch_id']])->id,
            'user_id' => fn (array $attributes): int => User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'source' => RosterSource::Joined,
            'joined_at' => CarbonImmutable::now()->startOfSecond(),
            'left_at' => null,
            'created_by_user_id' => fn (array $attributes): int => (int) $attributes['user_id'],
            'acting_user_id' => null,
            'device_id' => null,
        ];
    }

    public function scheduled(): self
    {
        return $this->state(fn (): array => ['source' => RosterSource::Scheduled, 'joined_at' => null]);
    }

    public function left(): self
    {
        return $this->state(fn (array $attributes): array => ['left_at' => $attributes['joined_at'] ?? CarbonImmutable::now()->startOfSecond()]);
    }
}
