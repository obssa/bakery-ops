<?php

declare(strict_types=1);

namespace Database\Factories\Orders;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\DiningTable;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active four-seat table of a fresh branch with a unique code; created_by_user_id is the authenticated actor when
 * there is one, else a fresh user of the table's branch (rule 4: no row without a creator).
 *
 * @extends Factory<DiningTable>
 */
final class DiningTableFactory extends Factory
{
    protected $model = DiningTable::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'branch_id' => Branch::factory(),
            'code' => "T{$n}",
            'name_en' => "Table {$n}",
            'name_am' => "ጠረጴዛ {$n}",
            'seats' => 4,
            'sort' => 0,
            'is_active' => true,
            'created_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
        ];
    }
}
