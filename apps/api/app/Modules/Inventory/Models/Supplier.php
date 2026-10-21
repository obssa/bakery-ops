<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Shared\Tenancy\BranchScoped;
use Carbon\CarbonImmutable;
use Database\Factories\Inventory\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who a purchase is bought from (phase-2 plan section 3, migration 3). Configuration, so it soft-deletes; a supplier's
 * name is a proper noun, shown as typed (no _en / _am pair). The actor stamp is set by the action that writes it.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $tin
 * @property string|null $notes
 * @property bool $is_active
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $actor_station_id
 * @property int|null $device_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
final class Supplier extends Model
{
    use BranchScoped;

    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'suppliers';

    /** @var list<string> the editable attributes; branch_id and the actor stamp are set by the action */
    protected $fillable = ['name', 'phone', 'tin', 'notes', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'is_active' => 'boolean',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'actor_station_id' => 'integer',
            'device_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }
}
