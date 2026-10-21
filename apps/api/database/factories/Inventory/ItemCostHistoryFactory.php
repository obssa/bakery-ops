<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\ItemCostHistory;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The first purchase of an item: 10 units at 5.0000 on an unvalued shelf (cost_before null), average after 5.0000. A row
 * only; the table is append-only, so it is created once and never updated.
 *
 * @extends Factory<ItemCostHistory>
 */
final class ItemCostHistoryFactory extends Factory
{
    protected $model = ItemCostHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'item_id' => fn (array $attributes): int => Item::factory()->ingredient('g')->create(['branch_id' => $attributes['branch_id']])->id,
            'source' => ItemCostHistory::SOURCE_PURCHASE,
            'purchase_line_id' => null,
            'qty_before' => '0',
            'cost_before' => null,
            'qty_in' => '10',
            'unit_cost_in' => '5',
            'cost_after' => '5',
            'user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'acting_user_id' => null,
            'created_at' => CarbonImmutable::now(),
        ];
    }
}
