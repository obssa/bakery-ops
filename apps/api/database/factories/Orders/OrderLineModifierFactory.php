<?php

declare(strict_types=1);

namespace Database\Factories\Orders;

use App\Modules\Catalog\Models\Modifier;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Models\OrderLineModifier;
use App\Shared\Tenancy\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The snapshot of a free Catalog modifier of the line's product (no price, no recipe change), written with a fresh
 * line; set price_delta_snapshot / recipe_delta_snapshot as needed.
 *
 * @extends Factory<OrderLineModifier>
 */
final class OrderLineModifierFactory extends Factory
{
    protected $model = OrderLineModifier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_line_id' => OrderLine::factory(),
            'branch_id' => fn (array $attributes): int => self::line($attributes)->branch_id,
            'modifier_id' => fn (array $attributes): int => Modifier::factory()->create([
                'branch_id' => $attributes['branch_id'],
                'item_id' => self::line($attributes)->item_id,
            ])->id,
            'name_en' => 'Extra cheese',
            'name_am' => 'ተጨማሪ አይብ',
            'price_delta_snapshot' => '0.00',
            'recipe_delta_snapshot' => [],
            'created_at' => fn (): CarbonImmutable => CarbonImmutable::now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function line(array $attributes): OrderLine
    {
        return OrderLine::query()->withoutGlobalScope(BranchScope::class)->findOrFail((int) $attributes['order_line_id']);
    }
}
