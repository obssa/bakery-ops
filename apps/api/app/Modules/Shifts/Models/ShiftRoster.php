<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Models;

use App\Modules\Shifts\Enums\RosterSource;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Shifts\ShiftRosterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who is on a shift (phase-3 plan section 3, migration 6; ADR 0013 rule 4). On duty = joined_at set and left_at null on the
 * running shift. Soft-deleted only while the shift has not started (a scheduled person removed from a scheduled shift); once
 * it has started the row is history and only left_at moves. One row per person and shift.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $shift_instance_id
 * @property int $user_id
 * @property RosterSource $source
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $left_at
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $device_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read ShiftInstance $shift
 */
final class ShiftRoster extends Model
{
    use BranchScoped;

    /** @use HasFactory<ShiftRosterFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'shift_roster';

    /** @var list<string> nothing: the roster is written by the actions of Shifts */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'shift_instance_id' => 'integer',
            'user_id' => 'integer',
            'source' => RosterSource::class,
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'device_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** Whether the person is on duty: joined and not left. */
    public function isOnDuty(): bool
    {
        return $this->joined_at !== null && $this->left_at === null;
    }

    /**
     * @return BelongsTo<ShiftInstance, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(ShiftInstance::class, 'shift_instance_id');
    }

    protected static function newFactory(): ShiftRosterFactory
    {
        return ShiftRosterFactory::new();
    }
}
