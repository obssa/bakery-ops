<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Carbon\CarbonImmutable;
use Database\Factories\Inventory\PurchaseLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One item bought on a purchase (phase-2 plan section 3, migration 5). qty is in unit_id, qty_base in the item's base unit
 * (what the movement carries). unit_cost is the rate per unit_id as typed, unit_cost_base the rate per BASE unit, half-up
 * to 4 dp (what the moving average uses): UnitCost values, never Money, whose two decimals cannot hold a cost per gram.
 * line_total is Money (qty x unit_cost, half-up to 2 dp). A draft's replaced lines are soft-deleted.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $purchase_id
 * @property string $client_uuid
 * @property int $item_id
 * @property Quantity $qty
 * @property int $unit_id
 * @property Quantity $qty_base
 * @property UnitCost $unit_cost
 * @property UnitCost $unit_cost_base
 * @property Money $line_total
 * @property int|null $movement_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Purchase $purchase
 */
final class PurchaseLine extends Model
{
    use BranchScoped;

    /** @use HasFactory<PurchaseLineFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'purchase_lines';

    /** @var list<string> what a client chooses; the converted quantity, the base rate, the total and the movement are set by the actions */
    protected $fillable = ['client_uuid', 'item_id', 'qty', 'unit_id', 'unit_cost'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'purchase_id' => 'integer',
            'item_id' => 'integer',
            'qty' => Quantity::class,
            'unit_id' => 'integer',
            'qty_base' => Quantity::class,
            'unit_cost' => UnitCost::class,
            'unit_cost_base' => UnitCost::class,
            'line_total' => Money::class,
            'movement_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Purchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    protected static function newFactory(): PurchaseLineFactory
    {
        return PurchaseLineFactory::new();
    }
}
