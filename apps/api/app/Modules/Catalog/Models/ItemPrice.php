<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\BumpsCatalogVersion;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Database\Factories\Catalog\ItemPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One period of an item's price (domain-model.md, Catalog): effective from effective_from (inclusive) until
 * effective_to (exclusive, null while open). History is never overwritten or deleted: a new price closes the open row
 * (Services\PriceHistory), which is the only update a row ever sees, hence no SoftDeletes (tests/Arch/SoftDeletesTest
 * allowlist). Times are stored in UTC.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $item_id
 * @property Money $price
 * @property string $currency
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $station_id
 * @property int|null $device_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Item $item
 */
final class ItemPrice extends Model
{
    use BranchScoped;
    use BumpsCatalogVersion;

    /** @use HasFactory<ItemPriceFactory> */
    use HasFactory;

    protected $table = 'item_prices';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'item_id', 'price', 'currency', 'effective_from', 'effective_to'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'item_id' => 'integer',
            'price' => Money::class,
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'station_id' => 'integer',
            'device_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Effective at $at: from inclusive, to exclusive. */
    public function isEffectiveAt(CarbonImmutable $at): bool
    {
        return $this->effective_from->lessThanOrEqualTo($at)
            && ($this->effective_to === null || $this->effective_to->greaterThan($at));
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    protected static function newFactory(): ItemPriceFactory
    {
        return ItemPriceFactory::new();
    }
}
