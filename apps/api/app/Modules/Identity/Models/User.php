<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Shared\Models\Branch;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Identity\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A person who works in a branch (domain-model.md, Identity). Phone is the login id (unique per branch); the short
 * code is an argon2id hash that is never shown after creation; roles and per-user permission overrides come from
 * spatie/laravel-permission (guard `web`, the model's default guard, also what the sanctum guard checks against).
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string $phone
 * @property string|null $email
 * @property string $password
 * @property string|null $short_code_hash
 * @property int $short_code_attempts
 * @property CarbonImmutable|null $pin_locked_until
 * @property string $locale
 * @property bool $is_active
 * @property string|null $photo_url
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Branch $branch
 */
final class User extends Authenticatable
{
    use BranchScoped;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use SoftDeletes;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'name', 'phone', 'email', 'password', 'locale', 'is_active', 'photo_url'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'short_code_hash'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'locale' => 'en',
        'is_active' => true,
        'short_code_attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'password' => 'hashed',
            'short_code_attempts' => 'integer',
            'pin_locked_until' => 'immutable_datetime',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<UserStationAssignment, $this>
     */
    public function stationAssignments(): HasMany
    {
        return $this->hasMany(UserStationAssignment::class, 'user_id');
    }

    /**
     * @return HasMany<ActingSession, $this>
     */
    public function actingSessions(): HasMany
    {
        return $this->hasMany(ActingSession::class, 'user_id');
    }

    public function hasShortCode(): bool
    {
        return $this->short_code_hash !== null && $this->short_code_hash !== '';
    }

    /** A named-user lock (short_code_attempts reached the limit) that has not expired yet. */
    public function isPinLocked(): bool
    {
        return $this->pin_locked_until !== null && $this->pin_locked_until->isFuture();
    }

    public function pinLockRemainingSeconds(): int
    {
        if (! $this->isPinLocked() || $this->pin_locked_until === null) {
            return 0;
        }

        return max(1, (int) CarbonImmutable::now()->diffInSeconds($this->pin_locked_until, false));
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
