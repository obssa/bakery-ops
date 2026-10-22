<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Models;

use App\Modules\Shifts\Enums\CountDecision;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Enums\SupersedeCause;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Shifts\StockCountFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A count of one location at one shift boundary (phase-3 plan section 3, migration 9). It moves only along CountStatus, through
 * the actions of Shifts, and is never deleted: a recount or an unlock revision is a new row (`seq` + 1) that supersedes it (no
 * SoftDeletes, tests/Arch/SoftDeletesTest.php allowlist). The lock columns (chain_seq .. locked_by_user_id) belong to step 3.4 and
 * stay null until then.
 *
 * `snapshot_at` and `locked_at` are DATETIME(6): they are read and written with their microseconds, whatever the model's own date
 * format (whole seconds) says. The place and the people are plain ids: Shifts reaches Inventory and Identity through contracts.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property int $shift_instance_id
 * @property int $location_id
 * @property CountType $type
 * @property int $seq
 * @property CountStatus $status
 * @property int|null $counted_by_user_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $snapshot_at
 * @property int|null $ledger_movement_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $cosigned_by_user_id
 * @property CarbonImmutable|null $cosigned_at
 * @property CountDecision|null $decision
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property SupersedeCause|null $supersede_cause
 * @property string|null $unlock_reason
 * @property int|null $superseded_by_id
 * @property CarbonImmutable|null $superseded_at
 * @property int|null $chain_seq
 * @property string|null $previous_hash
 * @property string|null $payload_hash
 * @property int|null $payload_version
 * @property string|null $canonical_payload
 * @property CarbonImmutable|null $locked_at
 * @property int|null $locked_by_user_id
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $actor_station_id
 * @property int|null $device_id
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ShiftInstance $shift
 * @property-read Collection<int, StockCountLine> $lines
 */
final class StockCount extends Model
{
    use BranchScoped;

    /** @use HasFactory<StockCountFactory> */
    use HasFactory;

    private const string MICRO = 'Y-m-d H:i:s.u';

    protected $table = 'stock_counts';

    /** @var list<string> nothing: a count is written by the actions (status, the people, the snapshot and the lock are theirs) */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'seq' => 1,
        'status' => 'draft',
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'shift_instance_id' => 'integer',
            'location_id' => 'integer',
            'type' => CountType::class,
            'seq' => 'integer',
            'status' => CountStatus::class,
            'counted_by_user_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'ledger_movement_id' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'cosigned_by_user_id' => 'integer',
            'cosigned_at' => 'immutable_datetime',
            'decision' => CountDecision::class,
            'decided_by_user_id' => 'integer',
            'decided_at' => 'immutable_datetime',
            'supersede_cause' => SupersedeCause::class,
            'superseded_by_id' => 'integer',
            'superseded_at' => 'immutable_datetime',
            'chain_seq' => 'integer',
            'payload_version' => 'integer',
            'locked_by_user_id' => 'integer',
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
     * The instant the balances were read under the row locks, with its microseconds.
     *
     * @return Attribute<CarbonImmutable|null, mixed>
     */
    protected function snapshotAt(): Attribute
    {
        return $this->microseconds();
    }

    /**
     * @return Attribute<CarbonImmutable|null, mixed>
     */
    protected function lockedAt(): Attribute
    {
        return $this->microseconds();
    }

    /**
     * @return Attribute<CarbonImmutable|null, mixed>
     */
    private function microseconds(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value): ?CarbonImmutable => $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC'),
            set: static fn (mixed $value): ?string => match (true) {
                $value === null => null,
                $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->utc()->format(self::MICRO),
                default => CarbonImmutable::parse((string) $value, 'UTC')->utc()->format(self::MICRO),
            },
        );
    }

    /**
     * @return BelongsTo<ShiftInstance, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(ShiftInstance::class, 'shift_instance_id');
    }

    /**
     * @return HasMany<StockCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'count_id')->orderBy('item_id');
    }

    protected static function newFactory(): StockCountFactory
    {
        return StockCountFactory::new();
    }
}
