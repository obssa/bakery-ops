<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Models;

use App\Modules\Shifts\Enums\ShiftOpeningMode;
use App\Modules\Shifts\Enums\ShiftOrigin;
use App\Modules\Shifts\Enums\ShiftStatus;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Shifts\ShiftInstanceFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A template on a date at a station, or an ad-hoc shift (phase-3 plan section 3, migration 5; ADR 0013). Its status moves
 * only along ShiftStatus, through the actions of Shifts (3.2b). Never deleted: it ends locked or cancelled (no SoftDeletes,
 * tests/Arch/SoftDeletesTest.php allowlist).
 *
 * `date` is the LOCAL start date in the branch's timezone (section 10.2). open_key is 1 while the shift is opening, open or
 * counting and NULL otherwise: the unique key (station_id, open_key) is "at most one running shift per station". slot_key is
 * the template id, or 0, and `sequence` numbers the instances of one slot on one date.
 *
 * The people are plain ids: Shifts reaches Identity through its contracts, never its models.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property int $station_id
 * @property int|null $template_id
 * @property int $slot_key
 * @property int $sequence
 * @property CarbonImmutable $date
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property ShiftOrigin $origin
 * @property ShiftStatus $status
 * @property int|null $open_key
 * @property ShiftOpeningMode|null $opening_mode
 * @property int|null $started_by_user_id
 * @property CarbonImmutable|null $started_at
 * @property int|null $opened_by_user_id
 * @property CarbonImmutable|null $opened_at
 * @property int|null $closed_by_user_id
 * @property CarbonImmutable|null $closing_started_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $locked_at
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancel_reason
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $actor_station_id
 * @property int|null $device_id
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Station $station
 * @property-read ShiftTemplate|null $template
 * @property-read Collection<int, ShiftRoster> $roster
 */
final class ShiftInstance extends Model
{
    use BranchScoped;

    /** @use HasFactory<ShiftInstanceFactory> */
    use HasFactory;

    protected $table = 'shift_instances';

    /** @var list<string> nothing: a shift is written by the actions (status, open_key, the stamps and the people are theirs) */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'slot_key' => 0,
        'sequence' => 1,
        'status' => 'scheduled',
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'station_id' => 'integer',
            'template_id' => 'integer',
            'slot_key' => 'integer',
            'sequence' => 'integer',
            'date' => 'immutable_date',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'origin' => ShiftOrigin::class,
            'status' => ShiftStatus::class,
            'open_key' => 'integer',
            'opening_mode' => ShiftOpeningMode::class,
            'started_by_user_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'opened_by_user_id' => 'integer',
            'opened_at' => 'immutable_datetime',
            'closed_by_user_id' => 'integer',
            'closing_started_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'cancelled_by_user_id' => 'integer',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
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
     * @return BelongsTo<ShiftTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'template_id')->withTrashed();
    }

    /**
     * @return HasMany<ShiftRoster, $this>
     */
    public function roster(): HasMany
    {
        return $this->hasMany(ShiftRoster::class, 'shift_instance_id');
    }

    protected static function newFactory(): ShiftInstanceFactory
    {
        return ShiftInstanceFactory::new();
    }
}
