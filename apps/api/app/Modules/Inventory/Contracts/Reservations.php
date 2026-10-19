<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\LedgerReader;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\Exploder;
use App\Shared\Values\Quantity;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Ramsey\Uuid\Uuid;

/**
 * manifest inventory.provides: "Reservations::reserve(lines[], ref) -> ok | Shortfall{ingredient, qty_short, station}",
 * "Reservations::release(ref) idempotent", "Reservations::consume(ref, actual[])" (traps.md, Reserved accounting).
 *
 * reserve: every line is exploded (Exploder: a to-order product into its required ingredients in base units with
 *   waste; anything else as itself), what lines share is summed per (item, location), the balance rows are locked in
 *   ascending order (Ledger::lock) and either everything fits in `available` and one `reservation` row per (item,
 *   location) is written, or nothing is written and the Shortfall names every shortage. available never goes below
 *   zero. The ref is the reservation's key: reserving it again returns the reservation it already is.
 * release: one `reservation_release` per reserved (item, location); a second release, a release after consume and an
 *   unknown ref are no-ops.
 * consume: turns the reservation into `production_consume` (or `sale`, for counter products at payment) exactly once:
 *   per reserved (item, location) one consumption row carrying the ref (-qty from on_hand and reserved); when less was
 *   actually used the rest is released, when more was used the excess is a consumption without the ref and must fit in
 *   what is available. A second consume is a no-op; consuming a released reservation is refused.
 *
 * Every row says which operation wrote it (ref_type reservation, reservation_release or reservation_consume), which is
 * how a settled reservation tells "released" from "consumed". Exactly-once does not rest on reads or isolation levels: every row a reservation writes carries a client_uuid derived
 * from (ref, role, item, location), and the row that settles a reservation (its release, or its consumption) has the
 * same derived uuid either way, so the unique (branch_id, client_uuid) index lets only one of two racing settlements
 * in. The loser rolls back whole and reports the settled state.
 */
final class Reservations
{
    private const string UUID_NAMESPACE = 'bakery-ops:reservation:';

    /** ref_type of the rows each operation writes: how a reservation was settled is read back from them. */
    private const string BY_RESERVE = 'reservation';

    private const string BY_RELEASE = 'reservation_release';

    private const string BY_CONSUME = 'reservation_consume';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly LedgerReader $reader,
        private readonly Exploder $exploder,
        private readonly Units $units,
        private readonly ConnectionResolverInterface $db,
    ) {}

    /**
     * @param  list<ReserveLine>  $lines
     *
     * @throws InventoryError inventory.recipe.missing (422) for a to-order product without a recipe;
     *                        inventory.reservation.closed (409) for a ref already released or consumed
     */
    public function reserve(array $lines, string $ref): Reserved|Shortfall
    {
        self::assertRef($ref);
        if ($lines === []) {
            throw new InvalidArgumentException('A reservation needs at least one line.');
        }
        $existing = $this->stateOf($ref);
        if ($existing !== null) {
            return $this->existing($ref, $existing);
        }

        $needs = $this->needs($lines);
        $pairs = array_values(array_map(static fn (array $n): array => [$n[0], $n[1]], $needs));

        try {
            /** @var Reserved|Shortfall $result */
            $result = $this->connection()->transaction(function () use ($needs, $pairs, $ref): Reserved|Shortfall {
                $levels = $this->ledger->lock($pairs);
                $shortages = [];
                foreach ($needs as $key => [$itemId, $locationId, $qty]) {
                    $available = $levels[$key]->available();
                    $available = $available->isNegative() ? Quantity::zero() : $available;
                    if ($qty->isGreaterThan($available)) {
                        $shortages[] = [$itemId, $locationId, $qty, $available];
                    }
                }
                if ($shortages !== []) {
                    return $this->shortfall($shortages);
                }

                $intents = [];
                $lines = [];
                foreach ($needs as [$itemId, $locationId, $qty]) {
                    $intents[] = MovementIntent::of($itemId, $locationId, $qty, MovementReason::Reservation, self::BY_RESERVE, null, $ref, self::uuid($ref, 'reserve', $itemId, $locationId));
                    $lines[] = new ReservedLine($itemId, $locationId, $qty);
                }
                $this->ledger->appendMany($intents);

                return new Reserved($ref, $lines);
            }, 3);

            return $result;
        } catch (UniqueConstraintViolationException $e) {
            // the same ref reserved concurrently: the other one won, and this is that reservation
            $state = $this->stateOf($ref);

            return $state === null ? throw $e : $this->existing($ref, $state);
        }
    }

    /**
     * @return list<Movement> the release rows written; empty when there was nothing (left) to release
     */
    public function release(string $ref): array
    {
        self::assertRef($ref);
        $state = $this->stateOf($ref);
        if ($state === null || $state['settled'] !== null) {
            return [];
        }

        $intents = [];
        foreach ($state['slots'] as [$itemId, $locationId, $qty]) {
            $intents[] = MovementIntent::of($itemId, $locationId, $qty->negated(), MovementReason::ReservationRelease, self::BY_RELEASE, null, $ref, self::uuid($ref, 'settle', $itemId, $locationId));
        }

        try {
            return $this->ledger->appendMany($intents);
        } catch (UniqueConstraintViolationException) {
            return []; // settled by a concurrent release or consume
        }
    }

    /**
     * @param  array<int, Quantity|string|int>|null  $actual  item id => what was actually used (base unit) where it
     *                                                        differs from the reservation; null = as reserved
     * @param  MovementReason  $as  production_consume (station lines, at ready) or sale (counter products, at payment)
     * @return list<Movement> the rows written; empty when the reservation was already consumed
     *
     * @throws InventoryError inventory.reservation.not_found (404), inventory.reservation.released (409),
     *                        inventory.stock.insufficient (409) for an excess beyond what is available
     */
    public function consume(string $ref, ?array $actual = null, MovementReason $as = MovementReason::ProductionConsume): array
    {
        if ($as !== MovementReason::ProductionConsume && $as !== MovementReason::Sale) {
            throw new InvalidArgumentException("A reservation is consumed as production_consume or sale, not {$as->value}.");
        }
        self::assertRef($ref);
        $state = $this->stateOf($ref);
        if ($state === null) {
            throw InventoryError::reservationNotFound($ref);
        }
        if ($state['settled'] === 'consumed') {
            return [];
        }
        if ($state['settled'] === 'released') {
            throw InventoryError::reservationReleased($ref);
        }

        $used = $this->actualPerSlot($state['slots'], $actual);
        $intents = [];
        $excess = [];
        foreach ($state['slots'] as $key => [$itemId, $locationId, $reserved]) {
            $qty = $used[$key];
            $settle = self::uuid($ref, 'settle', $itemId, $locationId);
            if ($qty->isZero()) {
                $intents[] = MovementIntent::of($itemId, $locationId, $reserved->negated(), MovementReason::ReservationRelease, self::BY_CONSUME, null, $ref, $settle);

                continue;
            }
            $withRef = $qty->isLessThan($reserved) ? $qty : $reserved;
            $intents[] = MovementIntent::of($itemId, $locationId, $withRef->negated(), $as, self::BY_CONSUME, null, $ref, $settle);
            if ($qty->isLessThan($reserved)) {
                $intents[] = MovementIntent::of($itemId, $locationId, $reserved->minus($qty)->negated(), MovementReason::ReservationRelease, self::BY_CONSUME, null, $ref, self::uuid($ref, 'remainder', $itemId, $locationId));
            } elseif ($qty->isGreaterThan($reserved)) {
                $over = $qty->minus($reserved);
                $excess[$key] = [$itemId, $locationId, $over];
                $intents[] = MovementIntent::of($itemId, $locationId, $over->negated(), $as, self::BY_CONSUME, null, null, self::uuid($ref, 'excess', $itemId, $locationId));
            }
        }

        try {
            /** @var list<Movement> $rows */
            $rows = $this->connection()->transaction(function () use ($intents, $excess): array {
                if ($excess !== []) {
                    // an excess is unplanned use: it may only take what nobody else holds
                    $levels = $this->ledger->lock(array_values(array_map(static fn (array $e): array => [$e[0], $e[1]], $excess)));
                    foreach ($excess as $key => [$itemId, $locationId, $over]) {
                        $available = $levels[$key]->available();
                        if ($over->isGreaterThan($available)) {
                            throw InventoryError::insufficientStock($itemId, $locationId, $levels[$key]->onHand->toString(), $levels[$key]->onHand->minus($over)->toString());
                        }
                    }
                }

                return $this->ledger->appendMany($intents);
            }, 3);

            return $rows;
        } catch (UniqueConstraintViolationException $e) {
            $state = $this->stateOf($ref);
            if ($state !== null && $state['settled'] === 'consumed') {
                return [];
            }
            if ($state !== null && $state['settled'] === 'released') {
                throw InventoryError::reservationReleased($ref);
            }
            throw $e;
        }
    }

    /**
     * What a reservation still holds; empty once it is released or consumed, or for an unknown ref.
     *
     * @return list<ReservedLine>
     */
    public function outstanding(string $ref): array
    {
        $state = $this->stateOf($ref);
        if ($state === null || $state['settled'] !== null) {
            return [];
        }

        return array_values(array_map(static fn (array $s): ReservedLine => new ReservedLine($s[0], $s[1], $s[2]), $state['slots']));
    }

    /**
     * The rows of a ref read back: what it reserved per (item, location) and whether (and how) it was settled.
     *
     * @return array{slots: array<string, array{0: int, 1: int, 2: Quantity}>, settled: 'released'|'consumed'|null}|null
     */
    private function stateOf(string $ref): ?array
    {
        $slots = [];
        $released = false;
        $consumed = false;
        foreach ($this->reader->forReservation($ref) as $row) {
            if ($row->reason === MovementReason::Reservation) {
                $slots[Ledger::key($row->itemId, $row->locationId)] = [$row->itemId, $row->locationId, $row->qty];
            }
            // settled by the operation that wrote the row (a consume that used none of an item still releases it)
            $released = $released || $row->refType === self::BY_RELEASE;
            $consumed = $consumed || $row->refType === self::BY_CONSUME;
        }
        if ($slots === []) {
            return null;
        }
        ksort($slots, SORT_NATURAL);

        return ['slots' => $slots, 'settled' => $consumed ? 'consumed' : ($released ? 'released' : null)];
    }

    /**
     * @param  array{slots: array<string, array{0: int, 1: int, 2: Quantity}>, settled: 'released'|'consumed'|null}  $state
     */
    private function existing(string $ref, array $state): Reserved
    {
        if ($state['settled'] !== null) {
            throw InventoryError::reservationClosed($ref);
        }

        return new Reserved($ref, array_values(array_map(static fn (array $s): ReservedLine => new ReservedLine($s[0], $s[1], $s[2]), $state['slots'])));
    }

    /**
     * What the lines need, summed per (item, location), ascending.
     *
     * @param  list<ReserveLine>  $lines
     * @return array<string, array{0: int, 1: int, 2: Quantity}>
     */
    private function needs(array $lines): array
    {
        $ids = array_values(array_unique(array_map(static fn (ReserveLine $l): int => $l->itemId, $lines)));
        $items = Item::query()->whereKey($ids)->get()->keyBy('id');

        $needs = [];
        foreach ($lines as $line) {
            $item = $items->get($line->itemId);
            if (! $item instanceof Item) {
                throw new InvalidArgumentException("Item {$line->itemId} is not a live item of this branch.");
            }
            $explosion = $this->exploder->explode($item) ?? throw InventoryError::recipeMissing($item->id);
            foreach ($explosion->needsFor($line->qty) as $itemId => $qty) {
                $key = Ledger::key($itemId, $line->locationId);
                $needs[$key] = [$itemId, $line->locationId, isset($needs[$key]) ? $needs[$key][2]->plus($qty) : $qty];
            }
        }
        uasort($needs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $needs;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: Quantity, 3: Quantity}>  $shortages  [item, location, needed, available]
     */
    private function shortfall(array $shortages): Shortfall
    {
        $itemIds = array_values(array_unique(array_map(static fn (array $s): int => $s[0], $shortages)));
        $locationIds = array_values(array_unique(array_map(static fn (array $s): int => $s[1], $shortages)));
        $unitOf = Item::query()->whereKey($itemIds)->pluck('unit_id', 'id')->all();
        $stationOf = Location::query()->whereKey($locationIds)->pluck('station_id', 'id')->all();

        $lines = [];
        foreach ($shortages as [$itemId, $locationId, $needed, $available]) {
            $station = $stationOf[$locationId] ?? null;
            $lines[] = new Shortage(
                $itemId,
                $locationId,
                $station === null ? null : (int) $station,
                $needed,
                $available,
                $needed->minus($available),
                $this->units->baseOf((int) ($unitOf[$itemId] ?? 0))->code,
            );
        }

        return new Shortfall($lines);
    }

    /**
     * @param  array<string, array{0: int, 1: int, 2: Quantity}>  $slots
     * @param  array<int, Quantity|string|int>|null  $actual
     * @return array<string, Quantity> what was used per slot
     */
    private function actualPerSlot(array $slots, ?array $actual): array
    {
        $used = [];
        $slotsOfItem = [];
        foreach ($slots as $key => [$itemId, , $reserved]) {
            $used[$key] = $reserved;
            $slotsOfItem[$itemId][] = $key;
        }
        foreach ($actual ?? [] as $itemId => $qty) {
            $keys = $slotsOfItem[$itemId] ?? [];
            if (count($keys) !== 1) {
                throw new InvalidArgumentException("Item {$itemId} is not reserved exactly once under this reservation, so its actual use cannot be placed.");
            }
            $qty = Quantity::of($qty);
            if ($qty->isNegative()) {
                throw new InvalidArgumentException("The actual use of item {$itemId} cannot be negative.");
            }
            $used[$keys[0]] = $qty;
        }

        return $used;
    }

    /** The client_uuid of a reservation row: the same (ref, role, item, location) always gives the same UUID. */
    private static function uuid(string $ref, string $role, int $itemId, int $locationId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, self::UUID_NAMESPACE."{$ref}:{$role}:{$itemId}:{$locationId}")->toString();
    }

    private static function assertRef(string $ref): void
    {
        if (! Str::isUuid($ref)) {
            throw new InvalidArgumentException('A reservation ref is a UUID.');
        }
    }

    private function connection(): Connection
    {
        $connection = $this->db->connection();
        if (! $connection instanceof Connection) {
            throw new LogicException('Reservations need a database connection.');
        }

        return $connection;
    }
}
