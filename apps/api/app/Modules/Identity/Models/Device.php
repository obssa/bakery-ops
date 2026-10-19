<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Identity\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A shared pad or till identified by the X-Device-Id fingerprint it sends (domain-model.md, Identity). Optional on
 * every route until Phase 5; a revoked device is refused as soon as it is revoked.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string $fingerprint
 * @property int|null $enrolled_by
 * @property CarbonImmutable $enrolled_at
 * @property CarbonImmutable|null $revoked_at
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
    protected $fillable = ['branch_id', 'name', 'fingerprint', 'enrolled_by', 'enrolled_at', 'revoked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'enrolled_by' => 'integer',
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
