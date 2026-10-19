<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Database\Factories\Catalog\RecipeLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One ingredient of a recipe, per yield (domain-model.md, Catalog): qty in `unit`, which converts to the ingredient's
 * base unit; waste_pct is a percentage (0 <= w < 100) kept as a decimal string. The ingredient may itself be a
 * product (a sandwich uses two slices of bread).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $recipe_id
 * @property int $ingredient_item_id
 * @property Quantity $qty
 * @property int $unit_id
 * @property bool $is_optional
 * @property string $waste_pct
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Recipe $recipe
 * @property-read Item $ingredient
 * @property-read Unit $unit
 */
final class RecipeLine extends Model
{
    use BranchScoped;

    /** @use HasFactory<RecipeLineFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'recipe_lines';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'recipe_id', 'ingredient_item_id', 'qty', 'unit_id', 'is_optional', 'waste_pct'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_optional' => false,
        'waste_pct' => '0.00',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'recipe_id' => 'integer',
            'ingredient_item_id' => 'integer',
            'qty' => Quantity::class,
            'unit_id' => 'integer',
            'is_optional' => 'boolean',
            'waste_pct' => 'decimal:2',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'ingredient_item_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id')->withTrashed();
    }

    protected static function newFactory(): RecipeLineFactory
    {
        return RecipeLineFactory::new();
    }
}
