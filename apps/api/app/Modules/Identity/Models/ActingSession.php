<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Identity\ActingSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short-code "acting as" window on a shared pad (domain-model.md, Identity): 10 minutes from the verified code,
 * ended earlier by the pad or by a lock. Sessions are history (who acted when, on which device): never deleted,
 * softly or otherwise, which is why the model carries no SoftDeletes (tests/Arch/SoftDeletesTest.php allowlist).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $user_id
 * @property int|null $device_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Device|null $device
 */
final class ActingSession extends Model
{
    use BranchScoped;

    /** @use HasFactory<ActingSessionFactory> */
    use HasFactory;

    protected $table = 'acting_sessions';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'user_id', 'device_id', 'started_at', 'expires_at', 'ended_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'user_id' => 'integer',
            'device_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    protected static function newFactory(): ActingSessionFactory
    {
        return ActingSessionFactory::new();
    }
}
