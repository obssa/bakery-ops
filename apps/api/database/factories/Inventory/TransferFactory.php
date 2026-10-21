<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Transfer;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A requested transfer of 5 kg (5000 g) of a fresh ingredient from a fresh store to a fresh kitchen of the same branch: a
 * row only, no movements. confirmed() and rejected() give the rows the CHECK constraints accept for those statuses (a row,
 * not the work of the actions: a confirmed one names no movements).
 *
 * @extends Factory<Transfer>
 */
final class TransferFactory extends Factory
{
    protected $model = Transfer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'client_uuid' => fn (): string => (string) Str::uuid(),
            'item_id' => fn (array $attributes): int => Item::factory()->ingredient('g')->create(['branch_id' => $attributes['branch_id']])->id,
            'from_location_id' => fn (array $attributes): int => Location::factory()->ofType(LocationType::Store)->create(['branch_id' => $attributes['branch_id']])->id,
            'to_location_id' => fn (array $attributes): int => Location::factory()->ofType(LocationType::Kitchen)->create(['branch_id' => $attributes['branch_id']])->id,
            'qty' => '5000',
            'status' => TransferStatus::Requested,
            'note' => null,
            'reject_reason' => null,
            'production_request_id' => null,
            'requested_by_user_id' => fn (array $attributes): int => $attributes['created_by_user_id']
                ?? app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'requested_at' => CarbonImmutable::now()->startOfSecond(),
            'decided_by_user_id' => null,
            'decided_at' => null,
            'out_movement_id' => null,
            'in_movement_id' => null,
            'created_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'acting_user_id' => null,
            'shift_instance_id' => null,
            'actor_station_id' => null,
            'device_id' => null,
            'version' => 1,
        ];
    }

    public function confirmed(): self
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Confirmed,
            'decided_by_user_id' => fn (array $attributes): int => (int) $attributes['requested_by_user_id'],
            'decided_at' => CarbonImmutable::now()->startOfSecond(),
            'version' => 2,
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn (): array => [
            'status' => TransferStatus::Rejected,
            'reject_reason' => 'Rejected by the test',
            'decided_by_user_id' => fn (array $attributes): int => (int) $attributes['requested_by_user_id'],
            'decided_at' => CarbonImmutable::now()->startOfSecond(),
            'version' => 2,
        ]);
    }
}
