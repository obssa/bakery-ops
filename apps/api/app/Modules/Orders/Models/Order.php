<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\CreatedVia;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Database\Factories\Orders\OrderFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order (domain-model.md, Orders; docs/phases/phase-1-plan.md section 3, migration 3). Its status moves only
 * along OrderStatus, through the actions of steps 1.3b-1.3d (state changes are actions, never a PATCH of status).
 * Never deleted: it ends closed, cancelled or voided (no SoftDeletes, tests/Arch/SoftDeletesTest.php allowlist).
 * order_no and business_date are given together at send (null while the order is an open cart). Money reads back as
 * Money in the row's currency (ADR 0004); owner_user_id is whose order it is (the acting waiter, else the creator).
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property int|null $order_no
 * @property CarbonImmutable|null $business_date
 * @property OrderType $type
 * @property int|null $dining_table_id
 * @property string|null $table_code
 * @property OrderStatus $status
 * @property CreatedVia $created_via
 * @property int $owner_user_id
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $station_id
 * @property int|null $device_id
 * @property Money $subtotal
 * @property Money $discount
 * @property Money $tax
 * @property Money $total
 * @property Money $paid_total
 * @property string $currency
 * @property string|null $notes
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property int|null $voided_by_user_id
 * @property int|null $void_approved_by_user_id
 * @property CarbonImmutable|null $due_at
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read DiningTable|null $diningTable
 * @property-read Collection<int, OrderLine> $lines
 * @property-read Collection<int, OrderEvent> $events
 */
final class Order extends Model
{
    use BranchScoped;

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $table = 'orders';

    /** @var list<string> what a client chooses; status, numbers, money and the actor stamp are set by the actions */
    protected $fillable = ['client_uuid', 'type', 'dining_table_id', 'table_code', 'created_via', 'notes', 'due_at'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'subtotal' => '0.00',
        'discount' => '0.00',
        'tax' => '0.00',
        'total' => '0.00',
        'paid_total' => '0.00',
        'currency' => Money::DEFAULT_CURRENCY,
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'order_no' => 'integer',
            'business_date' => 'immutable_date',
            'type' => OrderType::class,
            'dining_table_id' => 'integer',
            'status' => OrderStatus::class,
            'created_via' => CreatedVia::class,
            'owner_user_id' => 'integer',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'station_id' => 'integer',
            'device_id' => 'integer',
            'subtotal' => Money::class,
            'discount' => Money::class,
            'tax' => Money::class,
            'total' => Money::class,
            'paid_total' => Money::class,
            'sent_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'voided_by_user_id' => 'integer',
            'void_approved_by_user_id' => 'integer',
            'due_at' => 'immutable_datetime',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<DiningTable, $this>
     */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'order_id')->orderBy('id');
    }

    /**
     * @return HasMany<OrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class, 'order_id')->orderBy('id');
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
