<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Counts;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\LocationView;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Shifts\Cash\CashDeclarations;
use App\Modules\Shifts\Contracts\Locks;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Enums\CountType;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\StockCountLine;
use App\Shared\Actor\ActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Makes the draft counts of a shift boundary (phase-3 plan section 4, "How a count works"; section 10.8, "Count scope"): one
 * draft per stock place of the shift's station (never the cash drawer: buildCash makes that one), of the given type. A repeat finds the rows already made
 * (the client UUID is derived, UUIDv5 of count:{shift}:{location}:{type}:{seq}, so a retried start or close reaches the same
 * rows), and a place that already has a later revision answers that one. The lines of a draft are
 *   - the items with a non-zero on_hand at the place (read as committed: the expected figure is taken again, under the locks, at
 *     submit), and
 *   - the items of the previous count of the place, the newest submitted one: an item counted to zero stays visible until a count
 *     after it leaves it at zero (expected and counted both zero drops it), and an inactive item drops once it was counted at zero,
 *   - and nothing else; the counter adds "found an item" lines through the lines endpoint.
 * An opening count's lines carry `carried_qty`: what the latest approved closing of the place counted (Locks::previousClosing).
 * Called inside an action's transaction (start, close, Counts::draftsFor). Resolved per use.
 */
final class DraftBuilder
{
    public function __construct(
        private readonly Locations $locations,
        private readonly Stock $stock,
        private readonly Items $items,
        private readonly Locks $locks,
        private readonly ActorContext $actor,
        private readonly CashDeclarations $cash,
    ) {}

    /**
     * @return array{0: list<StockCount>, 1: bool} the counts, oldest place first, and whether any of them was made now
     */
    public function build(ShiftInstance $shift, CountType $type): array
    {
        $counts = [];
        $created = false;
        foreach ($this->locations->stockLocationsOf($shift->station_id) as $place) {
            $existing = StockCount::query()
                ->where('shift_instance_id', $shift->id)->where('location_id', $place->id)->where('type', $type->value)
                ->orderByDesc('seq')->first();
            if ($existing instanceof StockCount) {
                $counts[] = $existing;

                continue;
            }
            $counts[] = $this->make($shift, $type, $place);
            $created = true;
        }

        return [$counts, $created];
    }

    /**
     * The draft count of the station's cash drawer, with its empty declaration (step 3.5): null for a station with no cash drawer. A
     * repeat answers the row already made (any revision). `build()` stays the stock places only, so the stock contract is unchanged;
     * start and close ask for both.
     */
    public function buildCash(ShiftInstance $shift, CountType $type): ?StockCount
    {
        $drawer = $this->locations->cashDrawerOf($shift->station_id);
        if (! $drawer instanceof LocationView) {
            return null;
        }
        $existing = StockCount::query()
            ->where('shift_instance_id', $shift->id)->where('location_id', $drawer->id)->where('type', $type->value)
            ->orderByDesc('seq')->first();
        if ($existing instanceof StockCount) {
            return $existing;
        }
        $count = $this->make($shift, $type, $drawer);
        $this->cash->draft($count, $shift);

        return $count;
    }

    /** The derived client UUID of a count. */
    public static function clientUuid(int $shiftId, int $locationId, CountType $type, int $seq): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "count:{$shiftId}:{$locationId}:{$type->value}:{$seq}")->toString();
    }

    private function make(ShiftInstance $shift, CountType $type, LocationView $place): StockCount
    {
        $stamp = $this->actor->stamp();
        $now = CarbonImmutable::now()->startOfSecond();

        $count = new StockCount;
        $count->forceFill([
            'branch_id' => $shift->branch_id,
            'client_uuid' => self::clientUuid($shift->id, $place->id, $type, 1),
            'shift_instance_id' => $shift->id,
            'location_id' => $place->id,
            'type' => $type,
            'seq' => 1,
            'status' => CountStatus::Draft,
            'started_at' => $now,
            'created_by_user_id' => $stamp['created_by_user_id'],
            'acting_user_id' => $stamp['acting_user_id'],
            'actor_station_id' => $stamp['station_id'],
            'device_id' => $stamp['device_id'],
            'version' => 1,
        ])->save();

        $carried = [];
        if ($type === CountType::Opening) {
            foreach ($this->locks->previousClosing($place->id)?->lines()->get() ?? [] as $line) {
                if ($line->counted_qty !== null) {
                    $carried[$line->item_id] = $line->counted_qty->toString();
                }
            }
        }

        $rows = [];
        foreach ($this->itemIds($place) as $itemId) {
            $rows[] = [
                'branch_id' => $shift->branch_id,
                'count_id' => $count->id,
                'item_id' => $itemId,
                'carried_qty' => $carried[$itemId] ?? null,
                'reason_status' => 'none',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            DB::table('stock_count_lines')->insert($rows);
        }

        return $count;
    }

    /**
     * The items a new draft at the place lists, ascending.
     *
     * @return list<int>
     */
    private function itemIds(LocationView $place): array
    {
        $ids = [];
        foreach ($this->stock->at($place->id) as $level) {
            if (! $level->onHand->isZero()) {
                $ids[$level->itemId] = true;
            }
        }

        $previous = StockCount::query()
            ->where('location_id', $place->id)
            ->whereIn('status', array_map(static fn (CountStatus $s): string => $s->value, array_filter(CountStatus::cases(), static fn (CountStatus $s): bool => $s->isSubmitted())))
            ->orderByDesc('submitted_at')->orderByDesc('id')
            ->first();
        if ($previous instanceof StockCount) {
            $lines = StockCountLine::query()->where('count_id', $previous->id)->get();
            $summaries = $this->items->summaries(array_values(array_map('intval', $lines->pluck('item_id')->all())));
            foreach ($lines as $line) {
                $counted = $line->counted_qty;
                $expected = $line->expected_qty;
                $hadStock = ($counted !== null && $counted->isPositive()) || ($expected !== null && $expected->isPositive());
                $active = isset($summaries[$line->item_id]) && $summaries[$line->item_id]->isActive;
                if ($active ? $hadStock : ($counted !== null && $counted->isPositive())) {
                    $ids[$line->item_id] = true;
                }
            }
        }

        $list = array_keys($ids);
        sort($list);

        return $list;
    }
}
