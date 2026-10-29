<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\DeviceKind;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Identity\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A shared pad, till, KDS screen or print bridge identified by the X-Device-Id fingerprint it sends (domain-model.md, Identity). Since
 * step 5.1a it has a kind, a home station (informative), a client_uuid of its enrolment, a last_seen_at the device check writes at most
 * once a minute, who revoked it, and for a bridge the sha256 of its token (hidden: never in an answer, redacted in the audit log). The
 * station routes require an enrolled one once identity.require_enrolled_device is on (EnsureEnrolledDevice, ADR 0017); a revoked device
 * is refused as soon as it is revoked, and its id is never enrolled again (the tablet makes a new one).
 *
 * @property int $id
 * @property int $branch_id
 * @property string|null $client_uuid
 * @property string $name
 * @property DeviceKind $kind
 * @property int|null $station_id
 * @property string $fingerprint
 * @property string|null $token_hash
 * @property int|null $enrolled_by
 * @property CarbonImmutable $enrolled_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Device extends Model
{
    use BranchScoped;

    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'devices';

    /** @var list<string> */
    protected $fillable = ['branch_id', 'client_uuid', 'name', 'kind', 'station_id', 'fingerprint', 'token_hash', 'enrolled_by', 'enrolled_at', 'revoked_at', 'revoked_by'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @var array<string, mixed> */
    protected $attributes = ['kind' => 'tablet'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'kind' => DeviceKind::class,
            'station_id' => 'integer',
            'enrolled_by' => 'integer',
            'revoked_by' => 'integer',
            'last_seen_at' => 'immutable_datetime',
            'enrolled_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    protected static function newFactory(): DeviceFactory
    {
        return DeviceFactory::new();
    }
}
