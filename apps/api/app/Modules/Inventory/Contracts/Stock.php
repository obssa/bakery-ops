<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Ledger\LedgerReader;
use App\Modules\Inventory\Models\Location;
use DateTimeInterface;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * manifest inventory.provides: "Stock::of(item, location) -> {on_hand, reserved, available}" and
 * "Stock::balanceAt(item|location, timestamp)".
 *   of / at: the balance rows the Ledger keeps (ADR 0002), as committed; an (item, location) never moved is zero.
 *   balanceAt / locationAt / itemAt: the sum of the movements with created_at <= t (UTC), which is what a closing count
 *     expects (Shifts, Phase 3) and what a shift replay shows.
 *   movements: the ledger as written, newest first (GET /ledger; "movements by shift" in Phase 3 builds on it).
 * Items and locations are given as models or ids. Resolved per use (reads go through the branch-scoped models).
 */
final class Stock
{
    public function __construct(private readonly LedgerReader $reader) {}

    public function of(Item|int $item, Location|int $location): StockLevel
    {
        return $this->reader->level(self::id($item), self::id($location));
    }

    /**
     * Every balance at a location, keyed by item id.
     *
     * @return array<int, StockLevel>
     */
    public function at(Location|int $location): array
    {
        return $this->reader->levelsAt(self::id($location));
    }

    public function balanceAt(Item|int $item, Location|int $location, DateTimeInterface $at): StockLevel
    {
        $itemId = self::id($item);
        $locationId = self::id($location);

        return $this->reader->sumsAt($at, $itemId, $locationId)[0] ?? StockLevel::empty($itemId, $locationId);
    }

    /**
     * Every item's balance at a location as of $at, keyed by item id.
     *
     * @return array<int, StockLevel>
     */
    public function locationAt(Location|int $location, DateTimeInterface $at): array
    {
        $levels = [];
        foreach ($this->reader->sumsAt($at, null, self::id($location)) as $level) {
            $levels[$level->itemId] = $level;
        }

        return $levels;
    }

    /**
     * An item's balance at every location as of $at, keyed by location id.
     *
     * @return array<int, StockLevel>
     */
    public function itemAt(Item|int $item, DateTimeInterface $at): array
    {
        $levels = [];
        foreach ($this->reader->sumsAt($at, self::id($item)) as $level) {
            $levels[$level->locationId] = $level;
        }

        return $levels;
    }

    /**
     * @return LengthAwarePaginator<int, Movement>
     */
    public function movements(?int $itemId = null, ?int $locationId = null, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null, int $perPage = 50): LengthAwarePaginator
    {
        return $this->reader->paginate($itemId, $locationId, $from, $to, $perPage);
    }

    /** The movement an offline client recorded under $clientUuid, if it was written. */
    public function movementByClientUuid(string $clientUuid): ?Movement
    {
        return $this->reader->byClientUuid($clientUuid);
    }

    private static function id(Item|Location|int $model): int
    {
        return is_int($model) ? $model : $model->id;
    }

    /**
     * What the stock side of the pad's menu is computed from (GET /menu's ETag, with Catalog's Items::menuFingerprint):
     * the highest ledger movement id of the branch (every receipt, sale, waste and reservation is a movement) and the
     * state of its locations. Two cheap queries.
     *
     * @return array{last_movement_id: int|null, locations_n: int, locations_at: string|null}
     */
    public function fingerprint(): array
    {
        $row = Location::query()->withTrashed()->toBase()->selectRaw('count(*) as n, max(updated_at) as touched')->first();

        $latest = $row?->touched;

        return [
            'last_movement_id' => $this->reader->lastMovementId(),
            'locations_n' => (int) ($row?->n),
            'locations_at' => is_string($latest) ? $latest : null,
        ];
    }
}
