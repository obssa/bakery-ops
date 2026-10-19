<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Shared\I18n\Bilingual;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Catalog\RecipeStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One step of a recipe's method, in English and Amharic (rule 9), shown on the production card.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $recipe_id
 * @property int $step_order
 * @property string $text_en
 * @property string $text_am
 * @property Bilingual|null $text
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Recipe $recipe
 */
final class RecipeStep extends Model
{
    use BranchScoped;

    /** @use HasFactory<RecipeStepFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'recipe_steps';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'recipe_id', 'step_order', 'text_en', 'text_am'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'recipe_id' => 'integer',
            'step_order' => 'integer',
            'text' => Bilingual::class,
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

    protected static function newFactory(): RecipeStepFactory
    {
        return RecipeStepFactory::new();
    }
}
