<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;

/**
 * The derived state of dining tables (phase-1 plan section 4, GET /dining-tables; step 1.5), computed from the orders
 * of the tables in one grouped query, never stored:
 *
 *   waiting   the table has an order sent, in progress or ready (the stations are on it, or it waits to be served)
 *   ordering  the table has an open (unsent) order: the waiter is still taking it
 *   served    its only active orders are served and unpaid
 *   free      none of those (nothing, or only cancelled, voided, paid or closed orders)
 *   bill      in the enum, never produced in Phase 1: there is no "request the bill" action yet
 *
 * With several active orders the precedence is waiting, then ordering, then served.
 */
final class TableStates
{
    public const string FREE = 'free';

    public const string ORDERING = 'ordering';

    public const string WAITING = 'waiting';

    public const string SERVED = 'served';

    public const string BILL = 'bill';

    /**
     * @param  iterable<DiningTable>  $tables
     * @return array<int, string> table id => state
     */
    public function for(iterable $tables): array
    {
        $ids = [];
        foreach ($tables as $table) {
            $ids[] = $table->id;
        }
        $states = array_fill_keys($ids, self::FREE);
        if ($ids === []) {
            return $states;
        }

        $rows = Order::query()
            ->whereIn('dining_table_id', $ids)
            ->whereIn('status', [
                OrderStatus::Open->value, OrderStatus::Sent->value, OrderStatus::InProgress->value, OrderStatus::Ready->value, OrderStatus::Served->value,
            ])
            ->toBase()->select('dining_table_id', 'status')->groupBy('dining_table_id', 'status')
            ->get();

        /** @var array<int, array<string, true>> $present */
        $present = [];
        foreach ($rows as $row) {
            $present[(int) $row->dining_table_id][(string) $row->status] = true;
        }
        foreach ($present as $tableId => $statuses) {
            $states[$tableId] = match (true) {
                isset($statuses[OrderStatus::Sent->value]) || isset($statuses[OrderStatus::InProgress->value]) || isset($statuses[OrderStatus::Ready->value]) => self::WAITING,
                isset($statuses[OrderStatus::Open->value]) => self::ORDERING,
                isset($statuses[OrderStatus::Served->value]) => self::SERVED,
                default => self::FREE,
            };
        }

        return $states;
    }
}
