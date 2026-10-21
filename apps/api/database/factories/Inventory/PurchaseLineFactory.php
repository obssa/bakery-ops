<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\PurchaseLine;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Ten grams of a fresh ingredient at 5.0000 ETB per gram on a fresh draft purchase of the same branch: qty and qty_base
 * 10, unit_cost and unit_cost_base 5.0000, line_total 50.00. Override qty_base, unit_cost_base and line_total together
 * when the unit is not the base unit; the factory does not convert (the actions of step 2.2b do).
 *
 * @extends Factory<PurchaseLine>
 */
final class PurchaseLineFactory extends Factory
{
    protected $model = PurchaseLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'purchase_id' => fn (array $attributes): int => Purchase::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'client_uuid' => fn (): string => (string) Str::uuid(),
            'item_id' => fn (array $attributes): int => Item::factory()->ingredient('g')->create(['branch_id' => $attributes['branch_id']])->id,
            'qty' => '10',
            'unit_id' => fn (): int => Unit::query()->where('code', 'g')->firstOrFail()->id,
            'qty_base' => '10',
            'unit_cost' => '5',
            'unit_cost_base' => '5',
            'line_total' => '50.00',
            'movement_id' => null,
        ];
    }
}
