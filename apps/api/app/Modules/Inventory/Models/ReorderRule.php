<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\Item;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Database\Factories\Inventory\ReorderRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The minimum an item may fall to at a place, and how much to order then (phase-2 plan section 3, migration 14). Configuration,
 * so it soft-deletes; written only by Reorder\ReorderRules inside an AuditedAction. location_id NULL is a branch-wide rule
 * (location_key 0): the sum of available over the branch's stock places. The database allows one live rule per item and place.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $item_id
 * @property int|null $location_id
 * @property int $location_key
 * @property Quantity $min_qty
 * @property Quantity $reorder_qty
 * @property list<string> $notify_roles
 * @property bool $is_active
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $actor_station_id
 * @property int|null $device_id
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Item $item
 * @property-read Location|null $location
 */
final class ReorderRule extends Model
{
    use BranchScoped;

    /** @use HasFactory<ReorderRuleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'reorder_rules';

    /** @var list<string> nothing is mass assignable: ReorderRules sets every column explicitly */
    protected $guarded = ['*'];

    /** the generated column that makes "one live rule per item and place" a database guarantee; never part of an answer */
    protected $hidden = ['active_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'item_id' => 'integer',
            'location_id' => 'integer',
            'location_key' => 'integer',
            'min_qty' => Quantity::class,
            'reorder_qty' => Quantity::class,
            'notify_roles' => 'array',
            'is_active' => 'boolean',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'actor_station_id' => 'integer',
            'device_id' => 'integer',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    protected static function newFactory(): ReorderRuleFactory
    {
        return ReorderRuleFactory::new();
    }
}
