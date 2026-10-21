<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Modules\Production\Enums\BatchStatus;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScoped;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Database\Factories\Production\ProductionBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The work done for one production request (phase-2 plan section 3, migration 16). Its status moves only along BatchStatus,
 * through the actions of Production. Never deleted: started, finished, then confirmed (no SoftDeletes,
 * tests/Arch/SoftDeletesTest.php allowlist). The recipe version and both places are fixed at start. Quantities are in the
 * product base unit; produced and waste are null until the baker presses Done.
 *
 * The recipe, the places and the people are plain ids: Production reaches Catalog, Inventory and Identity through their
 * contracts and never their models (tests/Arch/ModuleBoundariesTest.php). output_movement_id names the production_output
 * movement a confirm wrote.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $client_uuid
 * @property int $request_id
 * @property int $station_id
 * @property BatchStatus $status
 * @property int $recipe_id
 * @property int $recipe_version
 * @property Quantity $planned_qty
 * @property Quantity|null $produced_qty
 * @property Quantity|null $waste_qty
 * @property int $consume_location_id
 * @property int $output_location_id
 * @property CarbonImmutable $started_at
 * @property int $started_by_user_id
 * @property CarbonImmutable|null $finished_at
 * @property int|null $finished_by_user_id
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $confirmed_by_user_id
 * @property string|null $send_back_note
 * @property int $send_back_count
 * @property int|null $output_movement_id
 * @property int $created_by_user_id
 * @property int|null $acting_user_id
 * @property int|null $shift_instance_id
 * @property int|null $actor_station_id
 * @property int|null $device_id
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ProductionRequest $request
 * @property-read Station $station
 */
final class ProductionBatch extends Model
{
    use BranchScoped;

    /** @use HasFactory<ProductionBatchFactory> */
    use HasFactory;

    protected $table = 'production_batches';

    /** @var list<string> nothing: a batch is written by the actions (status, the quantities, the sign-off and the actor stamp are theirs) */
    protected $fillable = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'started',
        'send_back_count' => 0,
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'request_id' => 'integer',
            'station_id' => 'integer',
            'status' => BatchStatus::class,
            'recipe_id' => 'integer',
            'recipe_version' => 'integer',
            'planned_qty' => Quantity::class,
            'produced_qty' => Quantity::class,
            'waste_qty' => Quantity::class,
            'consume_location_id' => 'integer',
            'output_location_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'started_by_user_id' => 'integer',
            'finished_at' => 'immutable_datetime',
            'finished_by_user_id' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'confirmed_by_user_id' => 'integer',
            'send_back_count' => 'integer',
            'output_movement_id' => 'integer',
            'created_by_user_id' => 'integer',
            'acting_user_id' => 'integer',
            'shift_instance_id' => 'integer',
            'actor_station_id' => 'integer',
            'device_id' => 'integer',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductionRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ProductionRequest::class, 'request_id');
    }

    /**
     * @return BelongsTo<Station, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id')->withTrashed();
    }

    protected static function newFactory(): ProductionBatchFactory
    {
        return ProductionBatchFactory::new();
    }
}
