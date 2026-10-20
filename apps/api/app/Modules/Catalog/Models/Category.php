<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Support\BumpsCatalogVersion;
use App\Shared\I18n\Bilingual;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Catalog\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A category of one kind of item in one branch (domain-model.md, Catalog), optionally under a parent of the same kind.
 *
 * @property int $id
 * @property int $branch_id
 * @property ItemKind $kind
 * @property string $name_en
 * @property string $name_am
 * @property Bilingual|null $name
 * @property int|null $parent_id
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Category|null $parent
 */
final class Category extends Model
{
    use BranchScoped;
    use BumpsCatalogVersion;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'categories';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'kind', 'name_en', 'name_am', 'parent_id', 'sort'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'sort' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'kind' => ItemKind::class,
            'name' => Bilingual::class,
            'parent_id' => 'integer',
            'sort' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'category_id');
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
