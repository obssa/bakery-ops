<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Time\DayRows;
use App\Shared\Time\LocalDay;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Generator;

/**
 * What Analytics may read of Orders (phase-4 plan section 2, contract analytics->facts): sales and their lines, as plain rows.
 * Reads only. A generator over a chunked query, one local day at a time, ordered (day, id); an empty range yields nothing.
 *
 * The clock is `settled_at` (the instant the balance reached zero; plan 10.2 R-J), bucketed by the branch's timezone through
 * LocalDay; both ends of the range are inclusive. Only `paid` and `closed` orders count: open, sent..served, cancelled and voided
 * orders are not sales. Branch-scoped through the models (the current branch), resolved per use.
 */
final class Facts
{
    /** @return Generator<int, SaleFact> */
    public function sales(LocalDay $range): Generator
    {
        return DayRows::generate(
            $range,
            'orders.id',
            fn (CarbonImmutable $start, CarbonImmutable $end) => Order::query()->toBase()
                ->select('orders.*')
                ->whereIn('orders.status', self::sold())
                ->where('orders.settled_at', '>=', DayRows::sql($start))
                ->where('orders.settled_at', '<', DayRows::sql($end)),
            fn (object $row, string $day): SaleFact => new SaleFact(
                (int) $row->id,
                $day,
                $range->hourOf((string) $row->settled_at),
                (string) $row->type,
                (string) $row->status,
                Money::of((string) $row->subtotal)->amount(),
                Money::of((string) $row->discount)->amount(),
                Money::of((string) $row->tax)->amount(),
                Money::of((string) $row->total)->amount(),
                Money::of((string) $row->paid_total)->amount(),
                $row->owner_user_id === null ? null : (int) $row->owner_user_id,
                $row->shift_instance_id === null ? null : (int) $row->shift_instance_id,
                $row->station_id === null ? null : (int) $row->station_id,
                (string) DayRows::iso($row->settled_at),
            ),
        );
    }

    /** @return Generator<int, LineFact> */
    public function lines(LocalDay $range): Generator
    {
        return DayRows::generate(
            $range,
            'order_lines.id',
            fn (CarbonImmutable $start, CarbonImmutable $end) => OrderLine::query()->toBase()
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->select('order_lines.*')
                ->whereIn('orders.status', self::sold())
                ->whereNotIn('order_lines.status', [LineStatus::Rejected->value, LineStatus::Voided->value])
                ->where('orders.settled_at', '>=', DayRows::sql($start))
                ->where('orders.settled_at', '<', DayRows::sql($end)),
            fn (object $row, string $day): LineFact => new LineFact(
                (int) $row->id,
                (int) $row->order_id,
                $day,
                (int) $row->item_id,
                Quantity::of((string) $row->qty)->toString(),
                Money::of((string) $row->unit_price_snapshot)->amount(),
                Money::of((string) $row->modifier_price_snapshot)->amount(),
                Money::of((string) $row->line_total)->amount(),
                $row->recipe_version === null ? null : (int) $row->recipe_version,
                $row->station_id === null ? null : (int) $row->station_id,
                $row->location_id === null ? null : (int) $row->location_id,
                (string) $row->status,
                DayRows::iso($row->accepted_at),
                DayRows::iso($row->ready_at),
                $row->prepared_by_user_id === null ? null : (int) $row->prepared_by_user_id,
            ),
        );
    }

    /** @return list<string> */
    private static function sold(): array
    {
        return [OrderStatus::Paid->value, OrderStatus::Closed->value];
    }
}
