<?php

declare(strict_types=1);

namespace App\Shared\Files;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One object in the file store (phase-4 plan section 3, M1). The object lives on `disk` under `key`
 * (receipts/{branch_id}/{yyyy}/{mm}/{uuid}.jpg: no original name); the row says how big, the sha256 of the stored bytes, who put it
 * and, once a payment or a refund claims it, what it is attached to. Soft-deleted; the object itself goes with the orphan purge (4.2b).
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property string $purpose
 * @property string $disk
 * @property string $key
 * @property string $mime
 * @property int $bytes
 * @property int $width
 * @property int $height
 * @property string $sha256
 * @property int|null $uploaded_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $station_id
 * @property int|null $device_id
 * @property string|null $attached_type
 * @property int|null $attached_id
 * @property CarbonImmutable|null $attached_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class StoredFile extends Model
{
    use BranchScoped;
    use SoftDeletes;

    protected $table = 'stored_files';

    /** @var list<string> nothing is mass-assigned: Files sets every column explicitly */
    protected $fillable = [];

    /** @var list<string> the object key and the hash stay out of any serialised form */
    protected $hidden = ['key', 'sha256'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'station_id' => 'integer',
            'device_id' => 'integer',
            'attached_id' => 'integer',
            'attached_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
