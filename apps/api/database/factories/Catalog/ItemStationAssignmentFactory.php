<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemStationAssignment;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An item routed to a station; pass item_id and station_id of one branch (the row takes the item's branch).
 *
 * @extends Factory<ItemStationAssignment>
 */
final class ItemStationAssignmentFactory extends Factory
{
    protected $model = ItemStationAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory()->product(),
            'branch_id' => fn (array $attributes): int => Item::query()->withoutGlobalScope(BranchScope::class)->findOrFail((int) $attributes['item_id'])->branch_id,
            'station_id' => fn (array $attributes): int => Station::factory()->create(['branch_id' => $attributes['branch_id']])->id,
        ];
    }
}
