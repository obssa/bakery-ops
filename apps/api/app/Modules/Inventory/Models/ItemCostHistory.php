<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Costs\CostHistoryIsAppendOnly;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Carbon\CarbonImmutable;
use Database\Factories\Inventory\ItemCostHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One update of an item's moving average (phase-2 plan section 3, migration 7): the quantity and average before, what
 * came in, the average after, and who. Written only by Costs\CostLedger. Append-only: the model refuses update and delete
 * and the database triggers refuse them too, so the average can always be replayed from this table. No updated_at, no
 * soft delete.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $item_id
 * @property string $source
 * @property int|null $purchase_line_id
 * @property Quantity $qty_before
 * @property UnitCost|null $cost_before
 * @property Quantity $qty_in
 * @property UnitCost $unit_cost_in
 * @property UnitCost $cost_after
 * @property int $user_id
 * @property int|null $acting_user_id
 * @property CarbonImmutable $created_at
 */
final class ItemCostHistory extends Model
{
    use BranchScoped;

    /** @use HasFactory<ItemCostHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const string SOURCE_PURCHASE = 'purchase';

    public const string SOURCE_OPENING = 'opening';

    protected $table = 'item_cost_history';

    /** @var list<string> nothing is mass assignable: CostLedger sets every column explicitly */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw CostHistoryIsAppendOnly::forOperation('update');
        });
        self::deleting(function (): never {
            throw CostHistoryIsAppendOnly::forOperation('delete');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'item_id' => 'integer',
            'purchase_line_id' => 'integer',
            'qty_before' => Quantity::class,
            'cost_before' => UnitCost::class,
            'qty_in' => Quantity::class,
            'unit_cost_in' => UnitCost::class,
            'cost_after' => UnitCost::class,
            'user_id' => 'integer',
            'acting_user_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): ItemCostHistoryFactory
    {
        return ItemCostHistoryFactory::new();
    }
}
