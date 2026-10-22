<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Models;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Shifts\VarianceReasonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Why a counted figure differs from the ledger (phase-3 plan section 3, migration 8). Per branch, bilingual, configuration: the
 * admin edits and switches reasons off; a reason is soft-deleted, never lost to the lines that used it. The code never changes.
 * `requires_note` makes the counter say more. The five defaults come from Support\DefaultVarianceReasons.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $code
 * @property string $name_en
 * @property string $name_am
 * @property bool $requires_note
 * @property bool $is_active
 * @property int $sort
 * @property int|null $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $actor_station_id
 * @property int|null $device_id
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class VarianceReason extends Model
{
    use BranchScoped;

    /** @use HasFactory<VarianceReasonFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'variance_reasons';

    /** @var list<string> nothing: written by the actions (the code is chosen once, the stamp is theirs) */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'requires_note' => false,
        'is_active' => true,
        'sort' => 0,
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'requires_note' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'actor_station_id' => 'integer',
            'device_id' => 'integer',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): VarianceReasonFactory
    {
        return VarianceReasonFactory::new();
    }
}
