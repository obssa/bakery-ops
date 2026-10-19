<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Modifier;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A free modifier of a to-order product with no recipe change; set price_delta / recipe_delta as needed.
 *
 * @extends Factory<Modifier>
 */
final class ModifierFactory extends Factory
{
    protected $model = Modifier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory()->product(),
            'branch_id' => fn (array $attributes): int => Item::query()->withoutGlobalScope(BranchScope::class)->findOrFail((int) $attributes['item_id'])->branch_id,
            'name_en' => 'Modifier',
            'name_am' => 'ማሻሻያ',
            'price_delta' => '0.00',
            'recipe_delta' => null,
        ];
    }
}
