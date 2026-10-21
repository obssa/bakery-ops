<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Database\Factories\Inventory\TransferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to move stock between two locations of the branch (phase-2 plan section 3, migration 8). Its status moves only
 * along TransferStatus, through the actions of Inventory\Transfers (never a PATCH of status). Never deleted: requested,
 * then confirmed or rejected (no SoftDeletes, tests/Arch/SoftDeletesTest.php allowlist). qty is in the item's base unit.
 * out_movement_id and in_movement_id name the two `transfer` rows a confirm wrote.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property int $item_id
 * @property int $from_location_id
 * @property int $to_location_id
 * @property Quantity $qty
 * @property TransferStatus $status
 * @property string|null $note
 * @property string|null $reject_reason
 * @property int|null $production_request_id
 * @property int $requested_by_user_id
 * @property CarbonImmutable $requested_at
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property int|null $out_movement_id
 * @property int|null $in_movement_id
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $actor_station_id
 * @property int|null $device_id
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Item $item
 * @property-read Location $fromLocation
 * @property-read Location $toLocation
 * @property-read User $requestedBy
 * @property-read User|null $decidedBy
 */
final class Transfer extends Model
{
    use BranchScoped;

    /** @use HasFactory<TransferFactory> */
    use HasFactory;

    protected $table = 'transfers';

    /** @var list<string> nothing: a transfer is written by the actions (status, the decision and the actor stamp are theirs) */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = [
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
            'item_id' => 'integer',
            'from_location_id' => 'integer',
            'to_location_id' => 'integer',
            'qty' => Quantity::class,
            'status' => TransferStatus::class,
            'production_request_id' => 'integer',
            'requested_by_user_id' => 'integer',
            'requested_at' => 'immutable_datetime',
            'decided_by_user_id' => 'integer',
            'decided_at' => 'immutable_datetime',
            'out_movement_id' => 'integer',
            'in_movement_id' => 'integer',
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
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    protected static function newFactory(): TransferFactory
    {
        return TransferFactory::new();
    }
}
