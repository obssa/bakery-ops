<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Models;

use App\Modules\Shifts\Enums\ReasonStatus;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Database\Factories\Shifts\StockCountLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of one count (phase-3 plan section 3, migration 10). The lines are the variance: expected (the ledger at the snapshot),
 * carried (the prefill of an opening count), counted, variance (counted minus expected), the reason and the note. Frozen by a
 * database trigger once the parent count is submitted: only reason_status, decision_note, adjustment_movement_id and updated_at
 * may change, and nothing once it is locked. Never deleted. Quantities are base-unit decimals (Quantity, ADR 0004). The item is a
 * plain id: Shifts reaches Catalog through its contracts.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $count_id
 * @property int $item_id
 * @property Quantity|null $expected_qty
 * @property Quantity|null $carried_qty
 * @property Quantity|null $counted_qty
 * @property Quantity|null $variance_qty
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $variance_reason_id
 * @property string|null $variance_note
 * @property ReasonStatus $reason_status
 * @property string|null $decision_note
 * @property int|null $adjustment_movement_id
 * @property string|null $photo_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read StockCount $stockCount
 */
final class StockCountLine extends Model
{
    use BranchScoped;

    /** @use HasFactory<StockCountLineFactory> */
    use HasFactory;

    protected $table = 'stock_count_lines';

    /** @var list<string> nothing: lines are written by the actions */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'reason_status' => 'none',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'count_id' => 'integer',
            'item_id' => 'integer',
            'expected_qty' => Quantity::class,
            'carried_qty' => Quantity::class,
            'counted_qty' => Quantity::class,
            'variance_qty' => Quantity::class,
            'confirmed_at' => 'immutable_datetime',
            'variance_reason_id' => 'integer',
            'reason_status' => ReasonStatus::class,
            'adjustment_movement_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<StockCount, $this>
     */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'count_id');
    }

    protected static function newFactory(): StockCountLineFactory
    {
        return StockCountLineFactory::new();
    }
}
