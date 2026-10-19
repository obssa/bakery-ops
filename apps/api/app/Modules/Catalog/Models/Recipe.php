<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Database\Factories\Catalog\RecipeFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A version of a product's bill of materials (domain-model.md, Catalog): yields yield_qty of the product in
 * yield_unit; its lines are per yield. One active version per product; old versions are kept (publishing new ones is
 * Phase 2). Read through Contracts\Recipes, which answers in base units only.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $product_item_id
 * @property Quantity $yield_qty
 * @property int $yield_unit_id
 * @property int $version
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Item $product
 * @property-read Unit $yieldUnit
 * @property-read Collection<int, RecipeLine> $lines
 * @property-read Collection<int, RecipeStep> $steps
 */
final class Recipe extends Model
{
    use BranchScoped;

    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'recipes';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'product_item_id', 'yield_qty', 'yield_unit_id', 'version', 'is_active'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'product_item_id' => 'integer',
            'yield_qty' => Quantity::class,
            'yield_unit_id' => 'integer',
            'version' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'product_item_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function yieldUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'yield_unit_id')->withTrashed();
    }

    /**
     * @return HasMany<RecipeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RecipeLine::class, 'recipe_id')->orderBy('id');
    }

    /**
     * @return HasMany<RecipeStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStep::class, 'recipe_id')->orderBy('step_order')->orderBy('id');
    }

    protected static function newFactory(): RecipeFactory
    {
        return RecipeFactory::new();
    }
}
