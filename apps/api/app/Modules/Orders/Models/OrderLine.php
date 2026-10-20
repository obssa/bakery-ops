<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Models\Item;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\RejectReason;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Database\Factories\Orders\OrderLineFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of an order (domain-model.md, Orders; ADR 0008): its own status along LineStatus, so the bar can be ready
 * while the kitchen cooks. qty is a Quantity in units of the product; the price snapshots and line_total are Money
 * (the currency of the configuration: a line has no currency column of its own). station_id routes the line (null for
 * a display-case line); location_id is where its stock is reserved, an id only: Orders learns it from Inventory's
 * Contracts\Locations and never reads Inventory models (tests/Arch/ModuleBoundariesTest.php). reservation_ref is the
 * line's one reservation. Never deleted (rejected or voided by status; SoftDeletesTest allowlist).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $order_id
 * @property string $client_uuid
 * @property int $item_id
 * @property Quantity $qty
 * @property Money $unit_price_snapshot
 * @property Money $modifier_price_snapshot
 * @property Money $line_total
 * @property int|null $recipe_version
 * @property int|null $station_id
 * @property int $location_id
 * @property string $reservation_ref
 * @property LineStatus $status
 * @property string|null $notes
 * @property int|null $accepted_by_user_id
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $prepared_by_user_id
 * @property CarbonImmutable|null $ready_at
 * @property int|null $served_by_user_id
 * @property CarbonImmutable|null $served_at
 * @property int|null $rejected_by_user_id
 * @property CarbonImmutable|null $rejected_at
 * @property RejectReason|null $reject_reason
 * @property string|null $reject_note
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $device_id
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Order $order
 * @property-read Item $item
 * @property-read Station|null $station
 * @property-read Collection<int, OrderLineModifier> $modifiers
 */
final class OrderLine extends Model
{
    use BranchScoped;

    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    protected $table = 'order_lines';

    /** @var list<string> what a client chooses; snapshots, routing, status and the actor stamp are set by the actions */
    protected $fillable = ['client_uuid', 'item_id', 'qty', 'notes'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'queued',
        'modifier_price_snapshot' => '0.00',
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'order_id' => 'integer',
            'item_id' => 'integer',
            'qty' => Quantity::class,
            'unit_price_snapshot' => Money::class,
            'modifier_price_snapshot' => Money::class,
            'line_total' => Money::class,
            'recipe_version' => 'integer',
            'station_id' => 'integer',
            'location_id' => 'integer',
            'status' => LineStatus::class,
            'accepted_by_user_id' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'prepared_by_user_id' => 'integer',
            'ready_at' => 'immutable_datetime',
            'served_by_user_id' => 'integer',
            'served_at' => 'immutable_datetime',
            'rejected_by_user_id' => 'integer',
            'rejected_at' => 'immutable_datetime',
            'reject_reason' => RejectReason::class,
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'device_id' => 'integer',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id');
    }

    /**
     * @return HasMany<OrderLineModifier, $this>
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderLineModifier::class, 'order_line_id')->orderBy('id');
    }

    protected static function newFactory(): OrderLineFactory
    {
        return OrderLineFactory::new();
    }
}
