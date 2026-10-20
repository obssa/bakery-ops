<?php

declare(strict_types=1);

namespace Database\Factories\Orders;

use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Shared\Tenancy\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An order.created event of a fresh open order: the order's branch and owner, written by its creator now. A row of
 * the append-only timeline: once created it is never updated or deleted.
 *
 * @extends Factory<OrderEvent>
 */
final class OrderEventFactory extends Factory
{
    protected $model = OrderEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'branch_id' => fn (array $attributes): int => self::order($attributes)->branch_id,
            'order_line_id' => null,
            'station_id' => null,
            'owner_user_id' => fn (array $attributes): int => self::order($attributes)->owner_user_id,
            'event' => OrderEventType::OrderCreated,
            'user_id' => fn (array $attributes): int => self::order($attributes)->created_by_user_id,
            'acting_user_id' => fn (array $attributes): ?int => self::order($attributes)->acting_user_id,
            'approved_by_user_id' => null,
            'actor_station_id' => null,
            'device_id' => null,
            'shift_instance_id' => null,
            'payload' => [],
            'created_at' => fn (): CarbonImmutable => CarbonImmutable::now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function order(array $attributes): Order
    {
        return Order::query()->withoutGlobalScope(BranchScope::class)->findOrFail((int) $attributes['order_id']);
    }
}
