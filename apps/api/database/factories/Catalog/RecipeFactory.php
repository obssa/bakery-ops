<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\Unit;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Version 1, active, yielding one piece of a to-stock product; add RecipeLine rows for the ingredients.
 *
 * @extends Factory<Recipe>
 */
final class RecipeFactory extends Factory
{
    protected $model = Recipe::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_item_id' => Item::factory()->product(Fulfilment::ToStock),
            'branch_id' => fn (array $attributes): int => Item::query()->withoutGlobalScope(BranchScope::class)->findOrFail((int) $attributes['product_item_id'])->branch_id,
            'yield_qty' => '1',
            'yield_unit_id' => fn (): int => Unit::query()->where('code', 'pcs')->firstOrFail()->id,
            'version' => 1,
            'is_active' => true,
        ];
    }
}
