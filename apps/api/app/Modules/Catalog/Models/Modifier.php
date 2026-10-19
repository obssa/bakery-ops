<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Shared\I18n\Bilingual;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Database\Factories\Catalog\ModifierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A modifier of a product (domain-model.md, Catalog): "no onion", large size. price_delta is money (may be negative);
 * recipe_delta lists signed ingredient quantities in their own units, {ingredient_item_id, qty, unit_id}, with qty a
 * decimal string; Contracts\Modifiers converts them to base units.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $item_id
 * @property string $name_en
 * @property string $name_am
 * @property Bilingual|null $name
 * @property Money $price_delta
 * @property list<array{ingredient_item_id: int, qty: string, unit_id: int}>|null $recipe_delta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Item $item
 */
final class Modifier extends Model
{
    use BranchScoped;

    /** @use HasFactory<ModifierFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'modifiers';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'item_id', 'name_en', 'name_am', 'price_delta', 'recipe_delta'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'price_delta' => '0.00',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'item_id' => 'integer',
            'name' => Bilingual::class,
            'price_delta' => Money::class,
            'recipe_delta' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    protected static function newFactory(): ModifierFactory
    {
        return ModifierFactory::new();
    }
}
