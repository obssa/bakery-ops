<?php

declare(strict_types=1);

namespace Database\Factories\Orders;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\CreatedVia;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An open dine-in cart taken on the waiter pad in a fresh branch: no number yet, zero money in ETB, owned by its
 * creator (the authenticated actor when there is one, else a fresh user of the branch). sent() numbers it on today's
 * business date as SendOrder will (step 1.3b); atTable() seats it with the table code snapshot. A row only: no lines,
 * no events, nothing reserved.
 *
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'client_uuid' => fn (): string => (string) Str::uuid(),
            'order_no' => null,
            'business_date' => null,
            'type' => OrderType::DineIn,
            'dining_table_id' => null,
            'table_code' => null,
            'status' => OrderStatus::Open,
            'created_via' => CreatedVia::WaiterPad,
            'created_by_user_id' => fn (array $attributes): int => app(ActorContext::class)->userId()
                ?? User::factory()->create(['branch_id' => $attributes['branch_id']])->id,
            'owner_user_id' => fn (array $attributes): int => (int) $attributes['created_by_user_id'],
            'acting_user_id' => null,
            'shift_instance_id' => null,
            'station_id' => null,
            'device_id' => null,
            'subtotal' => '0.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'total' => '0.00',
            'paid_total' => '0.00',
            'currency' => 'ETB',
            'version' => 1,
        ];
    }

    /** Sent and numbered $orderNo on today's business date (Addis Ababa), as SendOrder will leave it. */
    public function sent(int $orderNo = 1): self
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Sent,
            'order_no' => $orderNo,
            'business_date' => CarbonImmutable::now('Africa/Addis_Ababa')->toDateString(),
            'sent_at' => CarbonImmutable::now(),
        ]);
    }

    /** Seated at $table (same branch), with its code as the order's snapshot. */
    public function atTable(DiningTable $table): self
    {
        return $this->state(fn (): array => [
            'branch_id' => $table->branch_id,
            'type' => OrderType::DineIn,
            'dining_table_id' => $table->id,
            'table_code' => $table->code,
        ]);
    }
}
