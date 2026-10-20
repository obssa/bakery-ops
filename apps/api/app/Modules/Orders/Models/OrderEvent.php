<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Exceptions\OrderEventsAreAppendOnly;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Orders\OrderEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the append-only order timeline (domain-model.md, Orders; ADR 0009: its id is the live-update cursor of
 * the KDS by station_id and of the pad and till by owner_user_id). The model refuses update and delete, and the
 * database triggers refuse them too (tests/Invariants/AppendOnlyTest.php). No updated_at, no soft delete; created_at
 * keeps microseconds (DATETIME(6)).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $order_id
 * @property int|null $order_line_id
 * @property int|null $station_id
 * @property int $owner_user_id
 * @property OrderEventType $event
 * @property int $user_id
 * @property int|null $acting_user_id
 * @property int|null $approved_by_user_id
 * @property int|null $actor_station_id
 * @property int|null $device_id
 * @property int|null $shift_instance_id
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $created_at
 * @property-read Order $order
 * @property-read OrderLine|null $line
 */
final class OrderEvent extends Model
{
    use BranchScoped;

    /** @use HasFactory<OrderEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'order_events';

    /** @var list<string> nothing is mass assignable: the actions set every column explicitly */
    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw OrderEventsAreAppendOnly::forOperation('update');
        });
        self::deleting(function (): never {
            throw OrderEventsAreAppendOnly::forOperation('delete');
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
            'order_line_id' => 'integer',
            'station_id' => 'integer',
            'owner_user_id' => 'integer',
            'event' => OrderEventType::class,
            'user_id' => 'integer',
            'acting_user_id' => 'integer',
            'approved_by_user_id' => 'integer',
            'actor_station_id' => 'integer',
            'device_id' => 'integer',
            'shift_instance_id' => 'integer',
            'payload' => 'array',
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
     * @return BelongsTo<OrderLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    protected static function newFactory(): OrderEventFactory
    {
        return OrderEventFactory::new();
    }
}
