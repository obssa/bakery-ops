<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Enums\ItemKind;
use App\Modules\Catalog\Support\BumpsCatalogVersion;
use App\Shared\I18n\Bilingual;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Database\Factories\Catalog\ItemFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row of either inventory (rule 2, domain-model.md, Catalog): an ingredient (always bought in) or a product
 * fulfilled to order, to stock or bought in. Quantities about the item (default_batch_qty, its stock) are in the base
 * unit of `unit`. The actor stamp columns are written once, at creation, from ActorContext (rule 4).
 *
 * Step 4.1b (Ramin's answer to R-C, 2026-10-28): the admin's two refund settings. `refundable`: may the product be refunded at all.
 * `refund_restock`: does a refunded unit go back into stock; only a product whose sale takes the product itself out of stock can
 * (canRestock(): sold from stock or bought in), never a product made to order (its sale uses the recipe's ingredients) or an ingredient.
 * Left unset on create they take their natural value (refundable for a product, restock for a product sold from stock), so an item made
 * by the API, a seeder or a factory starts the same way the live rows were backfilled; and a save that leaves an item unable to restock
 * (made to order now) turns refund_restock off with it. Catalog\Contracts\RefundPolicy is how Orders reads them.
 *
 * @property int $id
 * @property int $branch_id
 * @property ItemKind $kind
 * @property Fulfilment $fulfilment
 * @property string $name_en
 * @property string $name_am
 * @property Bilingual|null $name
 * @property int|null $category_id
 * @property int $unit_id
 * @property string|null $sku
 * @property bool $is_active
 * @property CarbonImmutable|null $is_new_until
 * @property Quantity|null $default_batch_qty
 * @property int|null $production_station_id
 * @property bool $refundable
 * @property bool $refund_restock
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $station_id
 * @property int|null $device_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Unit $unit
 * @property-read Category|null $category
 * @property-read Station|null $productionStation
 * @property-read Collection<int, ItemStationAssignment> $stationAssignments
 */
final class Item extends Model
{
    use BranchScoped;
    use BumpsCatalogVersion;

    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'items';

    /** @var list<string> the editable attributes; branch_id and the actor stamp are set explicitly on create */
    protected $fillable = [
        'kind', 'fulfilment', 'name_en', 'name_am', 'category_id', 'unit_id', 'sku', 'is_active', 'is_new_until',
        'default_batch_qty', 'production_station_id', 'refundable', 'refund_restock',
    ];

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
            'kind' => ItemKind::class,
            'fulfilment' => Fulfilment::class,
            'name' => Bilingual::class,
            'category_id' => 'integer',
            'unit_id' => 'integer',
            'is_active' => 'boolean',
            'is_new_until' => 'immutable_date',
            'default_batch_qty' => Quantity::class,
            'production_station_id' => 'integer',
            'refundable' => 'boolean',
            'refund_restock' => 'boolean',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'station_id' => 'integer',
            'device_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (Item $item): void {
            $item->refundable ??= $item->kind === ItemKind::Product;
            $item->refund_restock ??= $item->canRestock();
        });
        self::saving(function (Item $item): void {
            if ($item->refund_restock === true && ! $item->canRestock()) {
                $item->refund_restock = false;
            }
        });
    }

    /**
     * Whether a refunded unit can go back into stock at all: only for a product whose sale takes the product itself out of stock (sold
     * from stock, or bought in), never for a product made to order (its sale consumes the recipe's ingredients) or an ingredient.
     */
    public function canRestock(): bool
    {
        return self::restocks($this->kind, $this->fulfilment);
    }

    /** canRestock() for an item of this kind and fulfilment, before it is saved. */
    public static function restocks(?ItemKind $kind, ?Fulfilment $fulfilment): bool
    {
        return $kind === ItemKind::Product && $fulfilment !== null && $fulfilment !== Fulfilment::ToOrder;
    }

    public function isProduct(): bool
    {
        return $this->kind === ItemKind::Product;
    }

    public function isIngredient(): bool
    {
        return $this->kind === ItemKind::Ingredient;
    }

    public function isBoughtIn(): bool
    {
        return $this->fulfilment === Fulfilment::BoughtIn;
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function productionStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'production_station_id');
    }

    /**
     * @return HasMany<ItemStationAssignment, $this>
     */
    public function stationAssignments(): HasMany
    {
        return $this->hasMany(ItemStationAssignment::class, 'item_id');
    }

    /**
     * @return HasMany<ItemPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ItemPrice::class, 'item_id');
    }

    /**
     * @return HasMany<Recipe, $this>
     */
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'product_item_id');
    }

    /**
     * @return HasMany<Modifier, $this>
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class, 'item_id');
    }

    protected static function newFactory(): ItemFactory
    {
        return ItemFactory::new();
    }
}
