<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Exceptions\RefundsAreAppendOnly;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One refund of a closed order (step 4.1b, phase-4 plan M7; written only by Actions\RefundOrder behind Orders::refund): why, how much
 * (amount, Money), whether after it every charged line of the order came back (is_full), who asked and whose PIN approved it, and the
 * actor stamp. Append-only: the model refuses update and delete, the database triggers refuse them one layer lower; no updated_at, no
 * soft delete. Its lines (OrderRefundLine) say what each refunded line did to stock.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property int $order_id
 * @property string $reason
 * @property Money $amount
 * @property bool $is_full
 * @property int $requested_by_user_id
 * @property int $approved_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $station_id
 * @property int|null $device_id
 * @property CarbonImmutable $created_at
 * @property-read Order $order
 * @property-read Collection<int, OrderRefundLine> $lines
 */
final class OrderRefund extends Model
{
    use BranchScoped;

    public const UPDATED_AT = null;

    protected $table = 'order_refunds';

    /** @var list<string> nothing is mass assignable: the action sets every column explicitly */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw RefundsAreAppendOnly::forOperation('order_refunds', 'update');
        });
        self::deleting(function (): never {
            throw RefundsAreAppendOnly::forOperation('order_refunds', 'delete');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'order_id' => 'integer',
            'amount' => Money::class,
            'is_full' => 'boolean',
            'requested_by_user_id' => 'integer',
            'approved_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'station_id' => 'integer',
            'device_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return HasMany<OrderRefundLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderRefundLine::class, 'order_refund_id')->orderBy('id');
    }
}
