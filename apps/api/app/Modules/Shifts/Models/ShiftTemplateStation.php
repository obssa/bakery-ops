<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Models;

use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Shifts\ShiftTemplateStationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A station a shift template applies to (phase-3 plan section 3, migration 4). One row per (template, station) even among
 * soft-deleted rows: a removed pair is restored, not duplicated. Carries no actor stamp: the template's audit row does.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $template_id
 * @property int $station_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read ShiftTemplate $template
 * @property-read Station $station
 */
final class ShiftTemplateStation extends Model
{
    use BranchScoped;

    /** @use HasFactory<ShiftTemplateStationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'shift_template_stations';

    /** @var list<string> */
    protected $fillable = ['template_id', 'station_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'template_id' => 'integer',
            'station_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ShiftTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'template_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id')->withTrashed();
    }

    protected static function newFactory(): ShiftTemplateStationFactory
    {
        return ShiftTemplateStationFactory::new();
    }
}
