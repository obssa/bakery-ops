<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Ledger;

use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use LogicException;

/**
 * The read side of the ledger, inside the Ledger namespace because nothing else may touch the movement and balance
 * models (tests/Arch/LedgerIsSoleWriterTest.php). Answers in Contracts DTOs only. Reads never lock: Stock::of and
 * Availability read the balance rows as committed; decisions that must hold (a reservation) lock through Ledger::lock.
 *
 * Sums over the movements use the same map as MovementReason, written as SQL (PLACE_SQL, ON_HAND_SQL, RESERVED_SQL;
 * literal strings, which the query builder's raw methods require; tests/Unit/Inventory/MovementReasonTest.php checks
 * them against the enum), so balanceAt and item_stock_view count exactly what the Ledger applied.
 */
final class LedgerReader
{
    /** The location a row changed: the one end it names, or for a transfer row the side its sign picks. */
    public const string PLACE_SQL = 'CASE WHEN from_location_id IS NULL THEN to_location_id WHEN to_location_id IS NULL THEN from_location_id '
        .'WHEN qty < 0 THEN from_location_id ELSE to_location_id END';

    /** The on_hand change of a row: qty, except for the two reasons that move reserved only (MovementReason). */
    public const string ON_HAND_SQL = "CASE WHEN reason IN ('reservation', 'reservation_release') THEN 0 ELSE qty END";

    /** The reserved change of a row: qty for the reservation reasons, and for a consumption carrying its reservation. */
    public const string RESERVED_SQL = "CASE WHEN reason IN ('reservation', 'reservation_release') THEN qty "
        ."WHEN reason IN ('production_consume', 'sale') AND reservation_ref IS NOT NULL THEN qty ELSE 0 END";

    public function level(int $itemId, int $locationId): StockLevel
    {
        $row = StockBalance::query()->where('item_id', $itemId)->where('location_id', $locationId)->first();

        return $row?->toLevel() ?? StockLevel::empty($itemId, $locationId);
    }

    /**
     * Every balance row at a location (optionally only some items), keyed by item id.
     *
     * @param  list<int>|null  $itemIds
     * @return array<int, StockLevel>
     */
    public function levelsAt(int $locationId, ?array $itemIds = null): array
    {
        $levels = [];
        $rows = StockBalance::query()
            ->where('location_id', $locationId)
            ->when($itemIds !== null, fn (Builder $q) => $q->whereIn('item_id', $itemIds ?? []))
            ->orderBy('item_id')
            ->get();
        foreach ($rows as $row) {
            $levels[$row->item_id] = $row->toLevel();
        }

        return $levels;
    }

    /**
     * The sum of the movements with created_at <= $at, per (item, location), optionally for one item and/or one
     * location (Stock::balanceAt).
     *
     * @return list<StockLevel> ascending (item_id, location_id)
     */
    public function sumsAt(DateTimeInterface $at, ?int $itemId = null, ?int $locationId = null): array
    {
        $place = self::PLACE_SQL;
        $rows = StockMovement::query()
            ->toBase()
            ->selectRaw('item_id, '.self::PLACE_SQL.' as place_id, SUM('.self::ON_HAND_SQL.') as on_hand, SUM('.self::RESERVED_SQL.') as reserved')
            ->where('created_at', '<=', CarbonImmutable::instance($at)->utc()->format('Y-m-d H:i:s'))
            ->when($itemId !== null, fn ($q) => $q->where('item_id', $itemId))
            ->when($locationId !== null, fn ($q) => $q
                ->where(fn ($w) => $w->where('from_location_id', $locationId)->orWhere('to_location_id', $locationId))
                ->whereRaw("({$place}) = ?", [$locationId]))
            ->groupByRaw("item_id, {$place}")
            ->orderBy('item_id')
            ->orderBy('place_id')
            ->get();

        $levels = [];
        foreach ($rows as $row) {
            $levels[] = new StockLevel(
                (int) $row->item_id,
                (int) $row->place_id,
                Quantity::of(self::decimal($row->on_hand)),
                Quantity::of(self::decimal($row->reserved)),
            );
        }

        return $levels;
    }

    /**
     * Every row written under a reservation_ref, oldest first.
     *
     * @return list<Movement>
     */
    public function forReservation(string $ref): array
    {
        return array_values(StockMovement::query()
            ->where('reservation_ref', $ref)
            ->orderBy('id')
            ->get()
            ->map(fn (StockMovement $m): Movement => $m->toMovement())
            ->all());
    }

    public function byClientUuid(string $clientUuid): ?Movement
    {
        return StockMovement::query()->where('client_uuid', $clientUuid)->first()?->toMovement();
    }

    /**
     * The ledger as written, newest first. A location filter keeps the rows that changed that location's balance (one
     * side of a transfer each), so the page adds up to it; from / to bound created_at inclusively.
     *
     * @return LengthAwarePaginator<int, Movement>
     */
    public function paginate(?int $itemId, ?int $locationId, ?DateTimeInterface $from, ?DateTimeInterface $to, int $perPage): LengthAwarePaginator
    {
        $place = self::PLACE_SQL;
        $page = StockMovement::query()
            ->when($itemId !== null, fn (Builder $q) => $q->where('item_id', $itemId))
            ->when($locationId !== null, fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w->where('from_location_id', $locationId)->orWhere('to_location_id', $locationId))
                ->whereRaw("({$place}) = ?", [$locationId]))
            ->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', CarbonImmutable::instance($from ?? CarbonImmutable::now())->utc()->format('Y-m-d H:i:s')))
            ->when($to !== null, fn (Builder $q) => $q->where('created_at', '<=', CarbonImmutable::instance($to ?? CarbonImmutable::now())->utc()->format('Y-m-d H:i:s')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $movements = array_values(array_map(
            static fn (StockMovement $m): Movement => $m->toMovement(),
            $page->items(),
        ));

        return new LengthAwarePaginator($movements, $page->total(), $page->perPage(), $page->currentPage(), [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /** A SUM over DECIMAL columns arrives as a decimal string (ADR 0004); anything else is a driver surprise. */
    private static function decimal(mixed $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }

        throw new LogicException('A stock sum arrived from the database as something other than a decimal string.');
    }
}
