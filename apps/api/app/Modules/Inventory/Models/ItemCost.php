<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Item;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Carbon\CarbonImmutable;
use Database\Factories\Inventory\ItemCostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The moving-average cost per base unit of an item (phase-2 plan section 3, migration 6). Derived state, written only by
 * Costs\CostLedger under this row's FOR UPDATE lock; a missing row means the item's stock is unvalued. Updated in place
 * and never deleted (tests/Arch/SoftDeletesTest.php allowlist); every update leaves an ItemCostHistory row.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $item_id
 * @property UnitCost $moving_avg_cost
 * @property Quantity $qty_basis
 * @property string $currency
 * @property CarbonImmutable $as_of
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Item $item
 */
final class ItemCost extends Model
{
    use BranchScoped;

    /** @use HasFactory<ItemCostFactory> */
    use HasFactory;

    protected $table = 'item_costs';

    /** @var list<string> nothing is mass assignable: CostLedger sets every column explicitly */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'item_id' => 'integer',
            'moving_avg_cost' => UnitCost::class,
            'qty_basis' => Quantity::class,
            'as_of' => 'immutable_datetime',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id')->withTrashed();
    }

    protected static function newFactory(): ItemCostFactory
    {
        return ItemCostFactory::new();
    }
}
