<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeLine;
use App\Modules\Catalog\Models\Unit;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 100 g of an ingredient of the recipe's branch, required, no waste.
 *
 * @extends Factory<RecipeLine>
 */
final class RecipeLineFactory extends Factory
{
    protected $model = RecipeLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'branch_id' => fn (array $attributes): int => Recipe::query()->withoutGlobalScope(BranchScope::class)->findOrFail((int) $attributes['recipe_id'])->branch_id,
            'ingredient_item_id' => fn (array $attributes): int => Item::factory()->ingredient('g')->create(['branch_id' => $attributes['branch_id']])->id,
            'qty' => '100',
            'unit_id' => fn (): int => Unit::query()->where('code', 'g')->firstOrFail()->id,
            'is_optional' => false,
            'waste_pct' => '0.00',
        ];
    }
}
