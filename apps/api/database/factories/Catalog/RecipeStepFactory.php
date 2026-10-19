<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Models\RecipeStep;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The first step of a recipe's method, in both languages.
 *
 * @extends Factory<RecipeStep>
 */
final class RecipeStepFactory extends Factory
{
    protected $model = RecipeStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipe_id' => Recipe::factory(),
            'branch_id' => fn (array $attributes): int => Recipe::query()->withoutGlobalScope(BranchScope::class)->findOrFail((int) $attributes['recipe_id'])->branch_id,
            'step_order' => 1,
            'text_en' => 'Mix everything.',
            'text_am' => 'ሁሉንም ቀላቅሉ።',
        ];
    }
}
