<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\BumpsCatalogVersion;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Catalog\ItemStationAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Where an item's order lines route (domain-model.md, Catalog): one row per (item, station), soft-deleted when the
 * assignment is withdrawn and restored when it returns, so the unique pair holds for the row's whole life.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $item_id
 * @property int $station_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Item $item
 * @property-read Station|null $station
 */
final class ItemStationAssignment extends Model
{
    use BranchScoped;
    use BumpsCatalogVersion;

    /** @use HasFactory<ItemStationAssignmentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'item_station_assignments';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'item_id', 'station_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'item_id' => 'integer',
            'station_id' => 'integer',
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
     * @return BelongsTo<Station, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id');
    }

    protected static function newFactory(): ItemStationAssignmentFactory
    {
        return ItemStationAssignmentFactory::new();
    }
}
