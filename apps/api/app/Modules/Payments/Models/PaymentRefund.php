<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The money side of a refund (phase-4 plan M10, written from step 4.3d): one row per payment a refund was paid back through, on that
 * payment's channel, so a split bill refunded across cash and Telebirr has two rows. order_refund_id is the Orders-side refund it pays out;
 * the actor stamp (refunded_by = who asked, approved_by = whose PIN, acting, shift = the shift the money left in, station, device) and
 * refunded_at. reference_no and proof_file_id are the outgoing transfer's, on a digital share only. Append-only (triggers): no
 * updated_at, never edited or deleted; branch-scoped (rule 8).
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property int $order_id
 * @property int $order_refund_id
 * @property int $payment_id
 * @property int $channel_id
 * @property Money $amount
 * @property string|null $reference_no
 * @property int|null $proof_file_id
 * @property int $refunded_by_user_id
 * @property int $approved_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $station_id
 * @property int|null $device_id
 * @property CarbonImmutable $refunded_at
 * @property CarbonImmutable $created_at
 * @property-read PaymentChannel $channel
 */
final class PaymentRefund extends Model
{
    use BranchScoped;

    public const UPDATED_AT = null;

    protected $table = 'payment_refunds';

    /** @var list<string> nothing is mass assignable: the refund action sets every column explicitly */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('payment_refunds is append-only: a refund row is never updated.');
        });
        self::deleting(function (): never {
            throw new LogicException('payment_refunds is append-only: a refund row is never deleted.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'order_id' => 'integer',
            'order_refund_id' => 'integer',
            'payment_id' => 'integer',
            'channel_id' => 'integer',
            'amount' => Money::class,
            'proof_file_id' => 'integer',
            'refunded_by_user_id' => 'integer',
            'approved_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'station_id' => 'integer',
            'device_id' => 'integer',
            'refunded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PaymentChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class, 'channel_id')->withTrashed();
    }
}
