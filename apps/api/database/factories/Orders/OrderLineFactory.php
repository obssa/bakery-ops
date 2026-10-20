<?php

declare(strict_types=1);

namespace Database\Factories\Orders;

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Models\Station;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A queued display-case line (a to-stock product, no station, stocked at the branch's display case) of a fresh open
 * order, one unit at 100.00; line_total is qty x (unit price + modifier price) at 2 dp, as OrderTotals will compute it
 * (step 1.3b). atStation() makes it a station line of a to-order product, stocked at the station's location, with
 * recipe version 1. The reservation_ref is a fresh UUID here; SendOrder derives it from the line's client UUID
 * (UUIDv5, ADR 0008). A row only: nothing is reserved.
 *
 * @extends Factory<OrderLine>
 */
final class OrderLineFactory extends Factory
{
    protected $model = OrderLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'branch_id' => fn (array $attributes): int => self::order($attributes)->branch_id,
            'client_uuid' => fn (): string => (string) Str::uuid(),
            'item_id' => fn (array $attributes): int => Item::factory()->product(Fulfilment::ToStock)
                ->create(['branch_id' => $attributes['branch_id']])->id,
            'qty' => '1.0000',
            'unit_price_snapshot' => '100.00',
            'modifier_price_snapshot' => '0.00',
            'line_total' => fn (array $attributes): string => Money::of(self::decimal($attributes['unit_price_snapshot']))
                ->plus(self::decimal($attributes['modifier_price_snapshot']))
                ->multipliedBy(Quantity::of(self::decimal($attributes['qty'])))
                ->amount(),
            'recipe_version' => null,
            'station_id' => null,
            'location_id' => fn (array $attributes): int => Location::factory()->ofType(LocationType::DisplayCase)
                ->create(['branch_id' => $attributes['branch_id']])->id,
            'reservation_ref' => fn (): string => (string) Str::uuid(),
            'status' => LineStatus::Queued,
            'created_by_user_id' => fn (array $attributes): int => self::order($attributes)->created_by_user_id,
            'acting_user_id' => fn (array $attributes): ?int => self::order($attributes)->acting_user_id,
            'shift_instance_id' => null,
            'device_id' => null,
            'version' => 1,
        ];
    }

    /** A line of a to-order product routed to $station (same branch), stocked at a location of that station. */
    public function atStation(Station $station): self
    {
        return $this->state(fn (): array => [
            'station_id' => $station->id,
            'recipe_version' => 1,
            'item_id' => fn (array $attributes): int => Item::factory()->product(Fulfilment::ToOrder)
                ->create(['branch_id' => $attributes['branch_id']])->id,
            'location_id' => fn (array $attributes): int => Location::factory()->ofType(LocationType::Kitchen)
                ->create(['branch_id' => $attributes['branch_id'], 'station_id' => $station->id])->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function order(array $attributes): Order
    {
        return Order::query()->withoutGlobalScope(BranchScope::class)->findOrFail((int) $attributes['order_id']);
    }

    /** A decimal string from what a test passed: a string, an int, Money or Quantity (never a float, ADR 0004). */
    private static function decimal(mixed $value): string
    {
        return match (true) {
            $value instanceof Money => $value->amount(),
            $value instanceof Quantity => $value->toString(),
            is_string($value), is_int($value) => (string) $value,
            default => throw new InvalidArgumentException('order line amounts are decimal strings, never floats (ADR 0004)'),
        };
    }
}
