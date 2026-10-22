<?php

declare(strict_types=1);

namespace Database\Factories\Shifts;

use App\Modules\Identity\Models\User;
use App\Modules\Shifts\Models\ShiftTemplate;
use App\Modules\Shifts\Support\TemplateWindow;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A daily Day template, 06:00 to 14:00 local, on a fresh branch. night() makes the cross-midnight 22:00 to 06:00 window.
 *
 * @extends Factory<ShiftTemplate>
 */
final class ShiftTemplateFactory extends Factory
{
    protected $model = ShiftTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'branch_id' => Branch::factory(),
            'name_en' => "Day {$n}",
            'name_am' => "ቀን {$n}",
            'start_time' => '06:00:00',
            'end_time' => '14:00:00',
            'days_mask' => 127,
            'is_active' => true,
            'sort' => 0,
            'created_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'acting_user_id' => null,
            'shift_instance_id' => null,
            'actor_station_id' => null,
            'device_id' => null,
            'version' => 1,
        ];
    }

    /** 22:00 to 06:00 the next morning, the cross-midnight window of section 10.2. */
    public function night(): self
    {
        return $this->state(fn (): array => ['name_en' => 'Night', 'name_am' => 'ሌሊት', 'start_time' => '22:00:00', 'end_time' => '06:00:00']);
    }

    /**
     * @param  list<int>  $isoWeekdays  1 (Monday) .. 7 (Sunday)
     */
    public function onDays(array $isoWeekdays): self
    {
        return $this->state(fn (): array => ['days_mask' => TemplateWindow::maskOf($isoWeekdays)]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
