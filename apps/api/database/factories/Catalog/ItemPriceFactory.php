<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\ItemPrice;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open price of 10.00 ETB effective since yesterday (UTC); pass effective_from / effective_to as UTC instants.
 *
 * @extends Factory<ItemPrice>
 */
final class ItemPriceFactory extends Factory
{
    protected $model = ItemPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory()->product(),
            'branch_id' => fn (array $attributes): int => self::branchOf($attributes['item_id']),
            'price' => '10.00',
            'currency' => 'ETB',
            'effective_from' => CarbonImmutable::now('UTC')->subDay()->startOfSecond(),
            'effective_to' => null,
            'created_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
        ];
    }

    private static function branchOf(mixed $itemId): int
    {
        return Item::query()->withoutGlobalScope(BranchScope::class)->findOrFail((int) $itemId)->branch_id;
    }
}
