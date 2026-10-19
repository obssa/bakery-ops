<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\LocationType;
use App\Shared\I18n\Bilingual;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Inventory\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place stock is held in (domain-model.md, Inventory): the store, the kitchen, the display case. Stock is per
 * location; station_id names the station that works from it (where a station's order lines are checked for stock).
 * The type is fixed once created.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name_en
 * @property string $name_am
 * @property Bilingual|null $name
 * @property int|null $station_id
 * @property LocationType $type
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Station|null $station
 */
final class Location extends Model
{
    use BranchScoped;

    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'locations';

    /** @var list<string> the editable attributes; branch_id is set explicitly on create */
    protected $fillable = ['name_en', 'name_am', 'station_id', 'type'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'name' => Bilingual::class,
            'station_id' => 'integer',
            'type' => LocationType::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id');
    }

    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }
}
