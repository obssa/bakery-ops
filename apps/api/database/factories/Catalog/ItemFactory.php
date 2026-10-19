<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Identity\Models\User;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An ingredient counted in grams by default; ingredient('kg') or product(Fulfilment::ToStock, 'pcs') for the rest.
 * The actor stamp's created_by_user_id is the authenticated actor when there is one, else a fresh user of the item's
 * branch (rule 4: no item without a creator).
 *
 * @extends Factory<Item>
 */
final class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'kind' => ItemKind::Ingredient,
            'fulfilment' => Fulfilment::BoughtIn,
            'name_en' => 'Item '.fake()->unique()->numberBetween(1, 999999),
            'name_am' => 'እቃ',
            'category_id' => null,
            'unit_id' => fn (): int => self::unitId('g'),
            'sku' => 'SKU-'.fake()->unique()->numerify('########'),
            'is_active' => true,
            'is_new_until' => null,
            'default_batch_qty' => null,
            'production_station_id' => null,
            'created_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
        ];
    }

    /** An ingredient (always bought in) counted in $unitCode. */
    public function ingredient(string $unitCode = 'g'): self
    {
        return $this->state(fn (): array => [
            'kind' => ItemKind::Ingredient,
            'fulfilment' => Fulfilment::BoughtIn,
            'unit_id' => self::unitId($unitCode),
        ]);
    }

    /** A product fulfilled as $fulfilment, counted in $unitCode. */
    public function product(Fulfilment $fulfilment = Fulfilment::ToOrder, string $unitCode = 'pcs'): self
    {
        return $this->state(fn (): array => [
            'kind' => ItemKind::Product,
            'fulfilment' => $fulfilment,
            'unit_id' => self::unitId($unitCode),
        ]);
    }

    private static function unitId(string $code): int
    {
        return Unit::query()->where('code', $code)->firstOrFail()->id;
    }
}
