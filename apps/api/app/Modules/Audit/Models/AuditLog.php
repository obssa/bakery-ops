<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Modules\Audit\AuditLogIsAppendOnly;
use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One audited write (domain-model.md, Audit). Rows are created by AuditedAction only; the model refuses update and
 * delete (the database trigger refuses them too, tests/Invariants/AppendOnlyTest.php). No timestamps: `at` is the
 * moment of the write and nothing about a row ever changes afterwards. Subjects are morph strings plus an id, so
 * Audit never imports another module's model (dependency-graph.md, cycle 4).
 *
 * @property int $id
 * @property int $branch_id
 * @property int $actor_id
 * @property int|null $acting_user_id
 * @property string $action
 * @property string $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property array<string, mixed>|null $context
 * @property int|null $shift_instance_id
 * @property int|null $station_id
 * @property int|null $device_id
 * @property string|null $ip
 * @property CarbonImmutable $at
 */
final class AuditLog extends Model
{
    use BranchScoped;

    protected $table = 'audit_log';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'branch_id', 'actor_id', 'acting_user_id', 'action', 'subject_type', 'subject_id',
        'before', 'after', 'context', 'shift_instance_id', 'station_id', 'device_id', 'ip', 'at',
    ];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw AuditLogIsAppendOnly::forOperation('update');
        });
        self::deleting(function (): never {
            throw AuditLogIsAppendOnly::forOperation('delete');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'actor_id' => 'integer',
            'acting_user_id' => 'integer',
            'subject_id' => 'integer',
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
            'shift_instance_id' => 'integer',
            'station_id' => 'integer',
            'device_id' => 'integer',
            'at' => 'immutable_datetime',
        ];
    }
}
