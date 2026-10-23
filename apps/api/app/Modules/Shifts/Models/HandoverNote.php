<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Models;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A handover note (phase-3 plan section 3, migration 14): what the outgoing shift leaves for the incoming one, written on a running shift,
 * for the next shift or for a named colleague. Written once and never changed or deleted (a database trigger refuses both; a correction is
 * a new note), so no SoftDeletes (tests/Arch/SoftDeletesTest.php allowlist) and no updated_at. `created_at` has microseconds: it is the clock
 * of the polled feed's overlap window. The people are plain ids: Shifts reaches Identity through its contracts.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property int $shift_instance_id
 * @property int $from_user_id
 * @property int|null $to_user_id
 * @property string $body
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $actor_station_id
 * @property int|null $device_id
 * @property CarbonImmutable $created_at
 * @property-read ShiftInstance $shift
 */
final class HandoverNote extends Model
{
    use BranchScoped;

    public $timestamps = false;

    protected $table = 'handover_notes';

    /** DATETIME(6): read and written with the microseconds */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @var list<string> nothing: a note is written by the action */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'shift_instance_id' => 'integer',
            'from_user_id' => 'integer',
            'to_user_id' => 'integer',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'actor_station_id' => 'integer',
            'device_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ShiftInstance, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(ShiftInstance::class, 'shift_instance_id');
    }
}
