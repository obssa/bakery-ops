<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Inventory\Ledger\FactsReader;
use App\Modules\Inventory\Ledger\LedgerReader;
use App\Modules\Inventory\Models\ItemCostHistory;
use App\Modules\Inventory\Models\StockAlertState;
use App\Shared\Time\DayRows;
use App\Shared\Time\LocalDay;
use App\Shared\Values\Quantity;
use App\Shared\Values\UnitCost;
use Carbon\CarbonImmutable;
use Generator;
use stdClass;

/**
 * What Analytics may read of Inventory (phase-4 plan section 2, contract analytics->facts). Reads only; each method is a generator
 * (an empty range yields nothing) and all days are local dates in the branch's timezone (LocalDay), both ends inclusive.
 *
 *   movements(range): ledger rows on the local day of `created_at`, ordered (day, id), chunked.
 *   closingBalances(day): what every item holds at every place at the end of that local day: the same sums as Stock::balanceAt
 *     (created_at <= the last second before the next local midnight), so it equals the oracle; items never moved are not listed.
 *   stockOuts(range): the `out` episodes (stock_alert_states) whose `active_since` falls on a day of the range, ordered (day, id),
 *     each with the station that owns its location (null for a place no station owns; step 4.4a).
 *   unitCosts(day): per item the item_cost_history row in force at the end of that local day (the last one written before the next
 *     local midnight), ordered by item id; an item with no history yet is not listed.
 * Branch-scoped through the models (the current branch), resolved per use.
 */
final class Facts
{
    /** @return Generator<int, MovementFact> */
    public function movements(LocalDay $range): Generator
    {
        $reader = new FactsReader;

        return DayRows::generate(
            $range,
            'id',
            fn (CarbonImmutable $start, CarbonImmutable $end) => $reader->movementsBetween(DayRows::sql($start), DayRows::sql($end)),
            fn (stdClass $row, string $day): MovementFact => new MovementFact(
                (int) $row->id,
                $day,
                (int) $row->item_id,
                $row->from_location_id === null ? null : (int) $row->from_location_id,
                $row->to_location_id === null ? null : (int) $row->to_location_id,
                Quantity::of((string) $row->qty)->toString(),
                (string) $row->reason,
                $row->ref_type === null ? null : (string) $row->ref_type,
                $row->ref_id === null ? null : (int) $row->ref_id,
                $row->shift_instance_id === null ? null : (int) $row->shift_instance_id,
                (string) DayRows::iso($row->created_at),
            ),
        );
    }

    /** @return Generator<int, ClosingBalanceFact> */
    public function closingBalances(string $day): Generator
    {
        $range = LocalDay::of($day);
        $endOfDay = $range->utcBounds()[1]->subSecond();

        foreach ((new LedgerReader)->sumsAt($endOfDay) as $level) {
            yield new ClosingBalanceFact($day, $level->itemId, $level->locationId, $level->onHand->toString(), $level->reserved->toString());
        }
    }

    /** @return Generator<int, StockOutFact> */
    public function stockOuts(LocalDay $range): Generator
    {
        return DayRows::generate(
            $range,
            'stock_alert_states.id',
            fn (CarbonImmutable $start, CarbonImmutable $end) => StockAlertState::query()->toBase()
                ->leftJoin('locations', 'locations.id', '=', 'stock_alert_states.location_id')
                ->select('stock_alert_states.id', 'stock_alert_states.item_id', 'stock_alert_states.location_id', 'stock_alert_states.active_since', 'stock_alert_states.cleared_at', 'locations.station_id')
                ->where('stock_alert_states.type', StockAlertState::OUT)
                ->where('stock_alert_states.active_since', '>=', DayRows::sql($start))
                ->where('stock_alert_states.active_since', '<', DayRows::sql($end)),
            fn (stdClass $row, string $day): StockOutFact => new StockOutFact(
                (int) $row->id,
                $day,
                (int) $row->item_id,
                $row->location_id === null ? null : (int) $row->location_id,
                (string) DayRows::iso($row->active_since),
                DayRows::iso($row->cleared_at),
                $row->station_id === null ? null : (int) $row->station_id,
            ),
        );
    }

    /** @return Generator<int, UnitCostFact> */
    public function unitCosts(string $day): Generator
    {
        $end = LocalDay::of($day)->utcBounds()[1];

        $latest = ItemCostHistory::query()->toBase()
            ->selectRaw('item_id, MAX(id) as last_id')
            ->where('created_at', '<', DayRows::sql($end))
            ->groupBy('item_id');

        $rows = ItemCostHistory::query()->toBase()
            ->joinSub($latest, 'latest', fn ($join) => $join->on('latest.last_id', '=', 'item_cost_history.id'))
            ->select('item_cost_history.item_id', 'item_cost_history.cost_after', 'item_cost_history.created_at')
            ->orderBy('item_cost_history.item_id')
            ->get();

        foreach ($rows as $row) {
            yield new UnitCostFact($day, (int) $row->item_id, UnitCost::of((string) $row->cost_after)->toString(), (string) DayRows::iso($row->created_at));
        }
    }
}
