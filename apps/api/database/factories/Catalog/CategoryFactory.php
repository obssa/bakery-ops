<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Category;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An ingredient category by default; product() for the other inventory.
 *
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'kind' => ItemKind::Ingredient,
            'name_en' => 'Category '.fake()->unique()->numberBetween(1, 99999),
            'name_am' => 'ምድብ',
            'parent_id' => null,
            'sort' => 0,
        ];
    }

    public function product(): self
    {
        return $this->state(fn (): array => ['kind' => ItemKind::Product]);
    }
}
