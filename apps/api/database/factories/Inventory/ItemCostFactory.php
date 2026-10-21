<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Models\ItemCost;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A moving average of 5.0000 ETB per base unit over 10 units, for a fresh ingredient of the same branch. A row only: no
 * history and no stock behind it (Costs\CostLedger writes the real thing).
 *
 * @extends Factory<ItemCost>
 */
final class ItemCostFactory extends Factory
{
    protected $model = ItemCost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'item_id' => fn (array $attributes): int => Item::factory()->ingredient('g')->create(['branch_id' => $attributes['branch_id']])->id,
            'moving_avg_cost' => '5.0000',
            'qty_basis' => '10.0000',
            'currency' => 'ETB',
            'as_of' => CarbonImmutable::now(),
            'version' => 1,
        ];
    }
}
