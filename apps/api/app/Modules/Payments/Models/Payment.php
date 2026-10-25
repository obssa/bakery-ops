<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Database\Factories\Payments\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money taken for an order (domain-model.md, Payments; docs/phases/phase-1-plan.md section 3, migration 8). Never
 * deleted: recorded, then verified, disputed or voided by status (Phase 4), so no SoftDeletes (tests/Arch/
 * SoftDeletesTest.php allowlist). Written once by Payments' RecordPayment inside the payment's transaction; money reads
 * back as Money in the row's currency (ADR 0004). reference_no is never logged (the audit snapshot redacts it).
 * Phase 4 (M4, step 4.3b): batch_uuid (payments recorded together), reference_norm (the normalised reference, redacted too),
 * receipt_file_id (the receipt photo; receipt_photo_key holds the same file's key, redacted), refunded_amount (0.00 until step
 * 4.3d) and the dispute columns of step 4.3c. refunded_amount is the database's default on insert: read the row again to see it.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property string|null $batch_uuid
 * @property int $order_id
 * @property int $channel_id
 * @property Money $amount
 * @property Money|null $tendered_amount
 * @property Money|null $change_amount
 * @property Money|null $refunded_amount
 * @property string $currency
 * @property string|null $reference_no
 * @property string|null $reference_norm
 * @property string|null $receipt_photo_key
 * @property int|null $receipt_file_id
 * @property PaymentStatus $status
 * @property int $received_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $station_id
 * @property int|null $device_id
 * @property CarbonImmutable $received_at
 * @property int|null $verified_by_user_id
 * @property CarbonImmutable|null $verified_at
 * @property int|null $disputed_by_user_id
 * @property CarbonImmutable|null $disputed_at
 * @property string|null $dispute_reason
 * @property CarbonImmutable|null $dispute_resolved_at
 * @property int|null $voided_by_user_id
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PaymentChannel $channel
 */
final class Payment extends Model
{
    use BranchScoped;

    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $table = 'payments';

    /** @var list<string> nothing is mass-assigned: RecordPayment sets every column explicitly */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'recorded',
        'currency' => Money::DEFAULT_CURRENCY,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'order_id' => 'integer',
            'channel_id' => 'integer',
            'amount' => Money::class,
            'tendered_amount' => Money::class,
            'change_amount' => Money::class,
            'refunded_amount' => Money::class,
            'status' => PaymentStatus::class,
            'received_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'station_id' => 'integer',
            'device_id' => 'integer',
            'received_at' => 'immutable_datetime',
            'verified_by_user_id' => 'integer',
            'verified_at' => 'immutable_datetime',
            'receipt_file_id' => 'integer',
            'disputed_by_user_id' => 'integer',
            'disputed_at' => 'immutable_datetime',
            'dispute_resolved_at' => 'immutable_datetime',
            'voided_by_user_id' => 'integer',
            'voided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PaymentChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class, 'channel_id')->withTrashed();
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
