<?php

declare(strict_types=1);

namespace Database\Factories\Production;

use App\Modules\Catalog\Models\Recipe;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A started batch of 12 for a fresh in-progress request, on version 1 of a fresh recipe of the request's product, consuming at
 * a fresh cake room location and putting the output in a fresh display case of the same branch: a row only, no movements.
 * finished() and confirmed() give the rows the CHECK constraints accept (confirmed by a second person).
 *
 * @extends Factory<ProductionBatch>
 */
final class ProductionBatchFactory extends Factory
{
    protected $model = ProductionBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'client_uuid' => fn (): string => (string) Str::uuid(),
            'request_id' => fn (array $attributes): int => ProductionRequest::factory()->status(RequestStatus::InProgress)->create(['branch_id' => $attributes['branch_id']])->id,
            'station_id' => fn (array $attributes): int => (int) ProductionRequest::query()->withoutGlobalScopes()->findOrFail((int) $attributes['request_id'])->station_id,
            'status' => BatchStatus::Started,
            'recipe_id' => fn (array $attributes): int => Recipe::factory()->create([
                'product_item_id' => ProductionRequest::query()->withoutGlobalScopes()->findOrFail((int) $attributes['request_id'])->product_item_id,
            ])->id,
            'recipe_version' => 1,
            'planned_qty' => '12',
            'produced_qty' => null,
            'waste_qty' => null,
            'consume_location_id' => fn (array $attributes): int => Location::factory()->ofType(LocationType::CakeRoom)->create(['branch_id' => $attributes['branch_id']])->id,
            'output_location_id' => fn (array $attributes): int => Location::factory()->ofType(LocationType::DisplayCase)->create(['branch_id' => $attributes['branch_id']])->id,
            'started_at' => CarbonImmutable::now()->startOfSecond(),
            'created_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'started_by_user_id' => fn (array $attributes): int => (int) $attributes['created_by_user_id'],
            'finished_at' => null,
            'finished_by_user_id' => null,
            'confirmed_at' => null,
            'confirmed_by_user_id' => null,
            'send_back_note' => null,
            'send_back_count' => 0,
            'output_movement_id' => null,
            'acting_user_id' => null,
            'shift_instance_id' => null,
            'actor_station_id' => null,
            'device_id' => null,
            'version' => 1,
        ];
    }

    /** Done pressed: 12 made, none wasted, by the baker who started it. */
    public function finished(): self
    {
        return $this->state(fn (): array => [
            'status' => BatchStatus::Finished,
            'produced_qty' => '12',
            'waste_qty' => '0',
            'finished_at' => CarbonImmutable::now()->startOfSecond(),
            'finished_by_user_id' => fn (array $attributes): int => (int) $attributes['started_by_user_id'],
            'version' => 2,
        ]);
    }

    /** Signed off by a second person of the branch (never the baker who finished it). */
    public function confirmed(): self
    {
        return $this->finished()->state(fn (): array => [
            'status' => BatchStatus::Confirmed,
            'confirmed_at' => CarbonImmutable::now()->startOfSecond(),
            'confirmed_by_user_id' => fn (array $attributes): int => User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'version' => 3,
        ]);
    }
}
