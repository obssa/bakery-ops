<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Supplier;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active supplier of a fresh branch. The actor stamp's created_by_user_id is the authenticated actor when there is
 * one, else a fresh user of the supplier's branch (rule 4).
 *
 * @extends Factory<Supplier>
 */
final class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => 'Supplier '.fake()->unique()->numberBetween(1, 999999),
            'phone' => null,
            'tin' => null,
            'notes' => null,
            'is_active' => true,
            'created_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'acting_user_id' => null,
            'shift_instance_id' => null,
            'actor_station_id' => null,
            'device_id' => null,
        ];
    }
}
