<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Identity\UserStationAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who may count what and who co-signs (domain-model.md, Identity): one row per (user, station), soft-deleted when the
 * assignment is withdrawn and restored when it returns, so the unique pair holds for the row's whole life.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $user_id
 * @property int $station_id
 * @property bool $can_count
 * @property bool $is_overseer
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read User $user
 * @property-read Station $station
 */
final class UserStationAssignment extends Model
{
    use BranchScoped;

    /** @use HasFactory<UserStationAssignmentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'user_station_assignments';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'user_id', 'station_id', 'can_count', 'is_overseer'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'can_count' => false,
        'is_overseer' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'user_id' => 'integer',
            'station_id' => 'integer',
            'can_count' => 'boolean',
            'is_overseer' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id');
    }

    protected static function newFactory(): UserStationAssignmentFactory
    {
        return UserStationAssignmentFactory::new();
    }
}
