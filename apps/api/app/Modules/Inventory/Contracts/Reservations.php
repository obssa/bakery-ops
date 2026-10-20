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
 * "Reservations::release(ref) idempotent", "Reservations::consume(ref, actual[])", and for an order's lines in one
 * lock pass reserveMany / releaseMany / consumeMany (traps.md, Reserved accounting; ADR 0008).
 *
 * reserve / reserveMany: every line is exploded (Exploder: a to-order product into its required ingredients in base
 *   units with waste; anything else as itself), the line's modifier deltas are applied (ReserveLine::needsWith), what a
 *   ref's lines share is summed per (item, location), and the balance rows of every ref are locked in ONE ascending pass
 *   (Ledger::lock), so two orders whose lines share ingredients in a different order never deadlock. The refs are then
 *   covered in the order given: a ref that does not fit in what earlier refs left is short and takes nothing. Either
 *   every ref fits and one `reservation` row per (item, location) per ref is written in one batch, or nothing is
 *   written and the Shortfall names every shortage with the ref it belongs to. available never goes below zero. A ref
 *   is the reservation's key: a ref already held is returned as it is (only the new refs are reserved), a settled one
 *   is refused.
 * release / releaseMany: one `reservation_release` per reserved (item, location) of every open ref, in one batch; a
 *   second release, a release after consume and an unknown ref are no-ops.
 * consume / consumeMany: turns the reservation into `production_consume` (station lines, at ready) or `sale` (counter
 *   products, at payment) exactly once: per reserved (item, location) one consumption row carrying the ref (-qty from
 *   on_hand and reserved). consume(ref, actual) also takes what was actually used: when less, the rest is released;
 *   when more, the excess is a consumption without the ref and must fit in what is available. A second consume is a
 *   no-op; consuming a released reservation (or an unknown one) is refused, for the whole batch.
 *
 * Every row says which operation wrote it (ref_type reservation, reservation_release or reservation_consume), which is
 * how a settled reservation tells "released" from "consumed". Exactly-once does not rest on reads or isolation levels:
 * every row a reservation writes carries a client_uuid derived from (ref, role, item, location), and the row that
 * settles a reservation (its release, or its consumption) has the same derived uuid either way, so the unique
 * (branch_id, client_uuid) index lets only one of two racing settlements in. The loser rolls back whole and reports
 * the settled state.
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
        $result = $this->reserveMany([$ref => $lines]);

        return $result instanceof Shortfall ? $result : $result[$ref];
    }

    /**
     * Reserve several refs (for Orders: one per order line) in one lock pass, covered in the order given.
     *
     * @param  array<string, list<ReserveLine>>  $byRef  ref (a UUID) => its lines
     * @return array<string, Reserved>|Shortfall every ref in the order given, or why nothing was reserved
     *
     * @throws InventoryError inventory.recipe.missing (422), inventory.reservation.closed (409)
     */
    public function reserveMany(array $byRef): array|Shortfall
    {
        if ($byRef === []) {
            throw new InvalidArgumentException('A reservation needs at least one ref.');
        }
        $held = [];
        $new = [];
        foreach ($byRef as $ref => $lines) {
            $ref = (string) $ref;
            self::assertRef($ref);
            if ($lines === []) {
                throw new InvalidArgumentException("Reservation {$ref} needs at least one line.");
            }
            $state = $this->stateOf($ref);
            if ($state !== null) {
                $held[$ref] = $this->existing($ref, $state);
            } else {
                $new[$ref] = $lines;
            }
        }
        if ($new === []) {
            return $this->inOrder($byRef, $held);
        }

        $needs = [];
        $union = [];
        foreach ($new as $ref => $lines) {
            $needs[$ref] = $this->needs($lines);
            foreach ($needs[$ref] as $key => [$itemId, $locationId]) {
                $union[$key] = [$itemId, $locationId];
            }
        }

        try {
            /** @var array<string, Reserved>|Shortfall $result */
            $result = $this->connection()->transaction(function () use ($needs, $union): array|Shortfall {
                $left = [];
                foreach ($this->ledger->lock(array_values($union)) as $key => $level) {
                    $available = $level->available();
                    $left[$key] = $available->isNegative() ? Quantity::zero() : $available;
                }

                $shortages = [];
                $intents = [];
                $reserved = [];
                foreach ($needs as $ref => $refNeeds) {
                    $short = [];
                    foreach ($refNeeds as $key => [$itemId, $locationId, $qty]) {
                        if ($qty->isGreaterThan($left[$key])) {
                            $short[] = [$itemId, $locationId, $qty, $left[$key], $ref];
                        }
                    }
                    if ($short !== []) {
                        array_push($shortages, ...$short); // a short ref takes nothing from the refs after it

                        continue;
                    }
                    $lines = [];
                    foreach ($refNeeds as $key => [$itemId, $locationId, $qty]) {
                        $left[$key] = $left[$key]->minus($qty);
                        $intents[] = MovementIntent::of($itemId, $locationId, $qty, MovementReason::Reservation, self::BY_RESERVE, null, $ref, self::uuid($ref, 'reserve', $itemId, $locationId));
                        $lines[] = new ReservedLine($itemId, $locationId, $qty);
                    }
                    $reserved[$ref] = new Reserved($ref, $lines);
                }
                if ($shortages !== []) {
                    return $this->shortfall($shortages);
                }
                $this->ledger->appendMany($intents);

                return $reserved;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // a ref reserved concurrently: the other call won, and if it holds every ref this is that reservation
            foreach (array_keys($new) as $ref) {
                $state = $this->stateOf($ref);
                if ($state === null) {
                    throw $e;
                }
                $held[$ref] = $this->existing($ref, $state);
            }

            return $this->inOrder($byRef, $held);
        }

        return $result instanceof Shortfall ? $result : $this->inOrder($byRef, $held + $result);
    }

    /**
     * @return list<Movement> the release rows written; empty when there was nothing (left) to release
     */
    public function release(string $ref): array
    {
        return $this->releaseMany([$ref]);
    }

    /**
     * Release every open ref in one batch (for Orders: the open lines of a voided order); settled and unknown refs are
     * skipped.
     *
     * @param  list<string>  $refs
     * @return list<Movement> the release rows written
     */
    public function releaseMany(array $refs): array
    {
        $byRef = [];
        foreach (array_values(array_unique($refs)) as $ref) {
            self::assertRef($ref);
            $state = $this->stateOf($ref);
            if ($state === null || $state['settled'] !== null) {
                continue;
            }
            foreach ($state['slots'] as [$itemId, $locationId, $qty]) {
                $byRef[$ref][] = MovementIntent::of($itemId, $locationId, $qty->negated(), MovementReason::ReservationRelease, self::BY_RELEASE, null, $ref, self::uuid($ref, 'settle', $itemId, $locationId));
            }
        }
        if ($byRef === []) {
            return [];
        }

        try {
            return $this->ledger->appendMany(array_merge(...array_values($byRef)));
        } catch (UniqueConstraintViolationException) {
            // one of them was settled by a concurrent release or consume: release the others one by one
            $rows = [];
            if (count($byRef) > 1) {
                foreach ($byRef as $intents) {
                    try {
                        array_push($rows, ...$this->ledger->appendMany($intents));
                    } catch (UniqueConstraintViolationException) {
                        // settled concurrently
                    }
                }
            }

            return $rows;
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
        self::assertConsumable($as);
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
            return $this->settledConcurrently([$ref], $e);
        }
    }

    /**
     * Consume several refs as reserved, in one batch (for Orders: the display-case lines of a paid order, as sale).
     * Refs already consumed are skipped; a released or unknown ref refuses the whole batch.
     *
     * @param  list<string>  $refs
     * @return list<Movement> the rows written
     *
     * @throws InventoryError inventory.reservation.not_found (404), inventory.reservation.released (409)
     */
    public function consumeMany(array $refs, MovementReason $as = MovementReason::ProductionConsume): array
    {
        self::assertConsumable($as);
        $intents = [];
        $open = [];
        foreach (array_values(array_unique($refs)) as $ref) {
            self::assertRef($ref);
            $state = $this->stateOf($ref);
            if ($state === null) {
                throw InventoryError::reservationNotFound($ref);
            }
            if ($state['settled'] === 'released') {
                throw InventoryError::reservationReleased($ref);
            }
            if ($state['settled'] === 'consumed') {
                continue;
            }
            $open[] = $ref;
            foreach ($state['slots'] as [$itemId, $locationId, $reserved]) {
                $intents[] = MovementIntent::of($itemId, $locationId, $reserved->negated(), $as, self::BY_CONSUME, null, $ref, self::uuid($ref, 'settle', $itemId, $locationId));
            }
        }
        if ($intents === []) {
            return [];
        }

        try {
            return $this->ledger->appendMany($intents);
        } catch (UniqueConstraintViolationException $e) {
            return $this->settledConcurrently($open, $e);
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
     * A consumption lost the race against a concurrent settlement: nothing was written. Consumed by the other side is
     * a no-op, released is a refusal.
     *
     * @param  list<string>  $refs
     * @return list<Movement> always empty
     */
    private function settledConcurrently(array $refs, UniqueConstraintViolationException $e): array
    {
        foreach ($refs as $ref) {
            $state = $this->stateOf($ref);
            if ($state !== null && $state['settled'] === 'released') {
                throw InventoryError::reservationReleased($ref);
            }
            if ($state === null || $state['settled'] === null) {
                throw $e;
            }
        }

        return [];
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
     * @param  array<array-key, list<ReserveLine>>  $byRef  the refs as the caller gave them
     * @param  array<string, Reserved>  $reserved
     * @return array<string, Reserved> in the caller's order
     */
    private function inOrder(array $byRef, array $reserved): array
    {
        $ordered = [];
        foreach (array_keys($byRef) as $ref) {
            $ordered[(string) $ref] = $reserved[(string) $ref];
        }

        return $ordered;
    }

    /**
     * What the lines need, with their modifier deltas, summed per (item, location), ascending.
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
            foreach ($line->needsWith($explosion->needsFor($line->qty)) as $itemId => $qty) {
                $key = Ledger::key($itemId, $line->locationId);
                $needs[$key] = [$itemId, $line->locationId, isset($needs[$key]) ? $needs[$key][2]->plus($qty) : $qty];
            }
        }
        uasort($needs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $needs;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: Quantity, 3: Quantity, 4: string}>  $shortages  [item, location, needed, available, ref]
     */
    private function shortfall(array $shortages): Shortfall
    {
        $itemIds = array_values(array_unique(array_map(static fn (array $s): int => $s[0], $shortages)));
        $locationIds = array_values(array_unique(array_map(static fn (array $s): int => $s[1], $shortages)));
        $unitOf = Item::query()->whereKey($itemIds)->pluck('unit_id', 'id')->all();
        $stationOf = Location::query()->whereKey($locationIds)->pluck('station_id', 'id')->all();

        $lines = [];
        foreach ($shortages as [$itemId, $locationId, $needed, $available, $ref]) {
            $station = $stationOf[$locationId] ?? null;
            $lines[] = new Shortage(
                $itemId,
                $locationId,
                $station === null ? null : (int) $station,
                $needed,
                $available,
                $needed->minus($available),
                $this->units->baseOf((int) ($unitOf[$itemId] ?? 0))->code,
                $ref,
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

    private static function assertConsumable(MovementReason $as): void
    {
        if ($as !== MovementReason::ProductionConsume && $as !== MovementReason::Sale) {
            throw new InvalidArgumentException("A reservation is consumed as production_consume or sale, not {$as->value}.");
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
