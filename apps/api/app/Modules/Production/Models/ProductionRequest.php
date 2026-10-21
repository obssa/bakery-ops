<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Modules\Production\Enums\RequestPriority;
use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Enums\RequestStatus;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Database\Factories\Production\ProductionRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A request for the producing station to make a to-stock product (phase-2 plan section 3, migration 15). Its status moves only
 * along RequestStatus, through the actions of Production (never a PATCH of status). Never deleted: it ends done or cancelled
 * (no SoftDeletes, tests/Arch/SoftDeletesTest.php allowlist). requested_qty is in the product base unit. open_stock_out_key is 1
 * while a stock_out request is open, else null.
 *
 * The product, the people and the order are kept as plain ids: Production reaches Catalog and Identity through their contracts
 * and never their models (tests/Arch/ModuleBoundariesTest.php), and never reads Orders at all.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property int $product_item_id
 * @property Quantity $requested_qty
 * @property CarbonImmutable|null $due_at
 * @property RequestPriority $priority
 * @property int $station_id
 * @property RequestReason $reason
 * @property RequestStatus $status
 * @property int $requested_by_user_id
 * @property int|null $assigned_to_user_id
 * @property int|null $linked_order_id
 * @property int|null $source_alert_id
 * @property int|null $open_stock_out_key
 * @property string|null $note
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $done_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $actor_station_id
 * @property int|null $device_id
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Station $station
 * @property-read ProductionBatch|null $batch
 */
final class ProductionRequest extends Model
{
    use BranchScoped;

    /** @use HasFactory<ProductionRequestFactory> */
    use HasFactory;

    protected $table = 'production_requests';

    /** @var list<string> nothing: a request is written by the actions (status, the stock-out key and the actor stamp are theirs) */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'priority' => 'normal',
        'status' => 'requested',
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'product_item_id' => 'integer',
            'requested_qty' => Quantity::class,
            'due_at' => 'immutable_datetime',
            'priority' => RequestPriority::class,
            'station_id' => 'integer',
            'reason' => RequestReason::class,
            'status' => RequestStatus::class,
            'requested_by_user_id' => 'integer',
            'assigned_to_user_id' => 'integer',
            'linked_order_id' => 'integer',
            'source_alert_id' => 'integer',
            'open_stock_out_key' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'done_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'actor_station_id' => 'integer',
            'device_id' => 'integer',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id')->withTrashed();
    }

    /**
     * @return HasOne<ProductionBatch, $this>
     */
    public function batch(): HasOne
    {
        return $this->hasOne(ProductionBatch::class, 'request_id');
    }

    protected static function newFactory(): ProductionRequestFactory
    {
        return ProductionRequestFactory::new();
    }
}
