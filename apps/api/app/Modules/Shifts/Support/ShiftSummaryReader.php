<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Support;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Contracts\ChannelTotal;
use App\Modules\Payments\Contracts\Totals;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;

/**
 * GET /shifts/{shift}/summary (phase-3 plan section 4): what the shift did, the review before a closing count.
 *   movements: what the movements stamped with the shift add up to, per place, item and reason (Stock::shiftSummary: production,
 *              sales, waste, transfers, adjustments), in the units of the items;
 *   cash:      the cash the till took and what each channel took (Payments' Totals, keyed by the payment's own shift); null at a
 *              station that is not the till;
 *   open_work: the lines still queued or accepted at the station now (Orders::openAt), a warning and not a block: closing with
 *              work open is allowed.
 * Resolved per use.
 */
final class ShiftSummaryReader
{
    public function __construct(
        private readonly Stock $stock,
        private readonly Locations $locations,
        private readonly Items $items,
        private readonly Totals $totals,
        private readonly Orders $orders,
    ) {}

    /**
     * @return array{movements: list<array<string, mixed>>, cash: array<string, mixed>|null, open_work: array{queued_lines: int, accepted_lines: int}}
     */
    public function of(ShiftInstance $shift): array
    {
        $rows = $this->stock->shiftSummary($shift->id);
        $places = $this->locations->views(array_map(static fn ($r): int => $r->locationId, $rows));
        $items = $this->items->summaries(array_map(static fn ($r): int => $r->itemId, $rows));

        $movements = [];
        foreach ($rows as $row) {
            $place = $places[$row->locationId] ?? null;
            $item = $items[$row->itemId] ?? null;
            $movements[] = [
                'location' => $place === null ? ['id' => $row->locationId] : ['id' => $place->id, 'name_en' => $place->nameEn, 'name_am' => $place->nameAm, 'type' => $place->type],
                'item' => ['id' => $row->itemId, 'name_en' => $item->nameEn ?? '', 'name_am' => $item->nameAm ?? '', 'unit' => $item->unit ?? ''],
                'reason' => $row->reason,
                'qty' => $row->qty,
                'rows' => $row->rows,
            ];
        }

        $station = Station::query()->withTrashed()->findOrFail($shift->station_id);
        $cash = $station->code === StationCode::Till ? [
            'cash_sales_total' => $this->totals->cash($shift->id)->amount(),
            'by_channel' => array_map(static fn (ChannelTotal $t): array => $t->toArray(), $this->totals->byChannel($shift->id)),
        ] : null;

        $queued = 0;
        $accepted = 0;
        foreach ($this->orders->openAt($station->id, CarbonImmutable::now()) as $order) {
            foreach ($order->lines as $line) {
                $queued += $line->status === 'queued' ? 1 : 0;
                $accepted += $line->status === 'accepted' ? 1 : 0;
            }
        }

        return ['movements' => $movements, 'cash' => $cash, 'open_work' => ['queued_lines' => $queued, 'accepted_lines' => $accepted]];
    }
}
