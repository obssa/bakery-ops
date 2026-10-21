<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\Supplier;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A draft purchase of a fresh branch from a fresh supplier, to be received in a fresh store, total 0.00 in ETB: a row
 * only, no lines, no movements. The supplier and the store are created in the purchase's own branch. confirmed() and
 * cancelled() give the rows the CHECK constraints accept for those statuses (a row, not the work of the actions).
 *
 * @extends Factory<Purchase>
 */
final class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'client_uuid' => fn (): string => (string) Str::uuid(),
            'supplier_id' => fn (array $attributes): int => Supplier::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'invoice_no' => 'INV-'.fake()->unique()->numberBetween(1, 999999),
            'purchase_date' => CarbonImmutable::now()->toDateString(),
            'location_id' => fn (array $attributes): int => Location::factory()->ofType(LocationType::Store)->create(['branch_id' => $attributes['branch_id']])->id,
            'status' => PurchaseStatus::Draft,
            'currency' => 'ETB',
            'total' => '0.00',
            'notes' => null,
            'confirmed_at' => null,
            'cancelled_at' => null,
            'cancel_reason' => null,
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
            'status' => PurchaseStatus::Confirmed,
            'confirmed_at' => CarbonImmutable::now(),
            'confirmed_by_user_id' => fn (array $attributes): int => (int) $attributes['created_by_user_id'],
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => [
            'status' => PurchaseStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now(),
            'cancel_reason' => 'Cancelled by the test',
        ]);
    }
}
