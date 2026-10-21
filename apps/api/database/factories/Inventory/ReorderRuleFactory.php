<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\ReorderRule;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A live, active rule for a fresh ingredient at a fresh store of the same branch: tell the admin and the manager when less than
 * 5000 g is left, reorder 25000 g. A row only: nothing evaluates it until a balance is evaluated. location_key follows
 * location_id (0 when the rule is branch-wide, so pass location_id => null). The actor stamp's created_by_user_id is the
 * authenticated actor when there is one, else a fresh user of the rule's branch (rule 4).
 *
 * @extends Factory<ReorderRule>
 */
final class ReorderRuleFactory extends Factory
{
    protected $model = ReorderRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'item_id' => fn (array $attributes): int => Item::factory()->ingredient('g')->create(['branch_id' => $attributes['branch_id']])->id,
            'location_id' => fn (array $attributes): int => Location::factory()->ofType(LocationType::Store)->create(['branch_id' => $attributes['branch_id']])->id,
            'location_key' => fn (array $attributes): int => (int) ($attributes['location_id'] ?? 0),
            'min_qty' => '5000',
            'reorder_qty' => '25000',
            'notify_roles' => ['admin', 'manager'],
            'is_active' => true,
            'created_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'acting_user_id' => null,
            'shift_instance_id' => null,
            'actor_station_id' => null,
            'device_id' => null,
            'version' => 1,
        ];
    }

    public function branchWide(): self
    {
        return $this->state(fn (): array => ['location_id' => null, 'location_key' => 0]);
    }
}
