<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\RefundDisposition;
use App\Modules\Orders\Exceptions\RefundsAreAppendOnly;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One refunded line of an order (step 4.1b, phase-4 plan M7): its quantity and money (line_amount, its line_total), the disposition its
 * product's settings gave it at the moment of the refund (restock: the reversing `return` movements in movement_ids; waste: none), kept
 * as written so a later change of the settings never rewrites a past refund. A line is refunded once (order_line_id is UNIQUE).
 * Append-only: the model refuses update and delete, the database triggers refuse them too; no updated_at, no soft delete.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $order_refund_id
 * @property int $order_line_id
 * @property Quantity $qty
 * @property Money $line_amount
 * @property RefundDisposition $disposition
 * @property list<int> $movement_ids
 * @property CarbonImmutable $created_at
 * @property-read OrderRefund $refund
 */
final class OrderRefundLine extends Model
{
    use BranchScoped;

    public const UPDATED_AT = null;

    protected $table = 'order_refund_lines';

    /** @var list<string> nothing is mass assignable: the action sets every column explicitly */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw RefundsAreAppendOnly::forOperation('order_refund_lines', 'update');
        });
        self::deleting(function (): never {
            throw RefundsAreAppendOnly::forOperation('order_refund_lines', 'delete');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'order_refund_id' => 'integer',
            'order_line_id' => 'integer',
            'qty' => Quantity::class,
            'line_amount' => Money::class,
            'disposition' => RefundDisposition::class,
            'movement_ids' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderRefund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(OrderRefund::class, 'order_refund_id');
    }
}
