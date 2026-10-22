<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Ledger;

use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Models\Location;
use App\Shared\Actor\ActorContext;
use App\Shared\Events\StockChanged;
use App\Shared\Shifts\OpenShifts;
use App\Shared\Tenancy\BranchScope;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * The only writer of stock_movements and stock_balances (rule 1, ADR 0002; tests/Arch/LedgerIsSoleWriterTest.php).
 *
 * append() / appendMany() write the movements and upsert the (item, location) balances in one transaction, all or
 * nothing: the balance rows are locked first with SELECT ... FOR UPDATE in ascending (item_id, location_id) order (the
 * same order for every caller, so two batches never deadlock on each other), the batch's effect is applied, and a
 * batch that would take on_hand or reserved below zero is refused whole (InventoryError). Every row carries the actor
 * stamp from ActorContext (rule 4); a write without an authenticated actor is refused. StockChanged is dispatched once
 * per changed balance, after the commit. Nested inside a caller's transaction (an AuditedAction, a reservation) the
 * batch becomes a savepoint of it.
 *
 * lock() exposes the same row locks to callers that must decide under them (Reservations: is enough available?).
 * Resolved per use: it carries the request-scoped ActorContext and CurrentBranch (traps.md, Octane).
 */
final class Ledger
{
    /** Deadlock / lock-wait retries of an outermost batch (Laravel retries only when the transaction is not nested). */
    private const int ATTEMPTS = 3;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly ActorContext $actor,
        private readonly CurrentBranch $branch,
        private readonly Dispatcher $events,
    ) {}

    /** The key of an (item, location) pair in the arrays the Ledger hands back. */
    public static function key(int $itemId, int $locationId): string
    {
        return $itemId.':'.$locationId;
    }

    public function append(MovementIntent $intent): Movement
    {
        return $this->appendMany([$intent])[0];
    }

    /**
     * Write every intent or none of them.
     *
     * @param  list<MovementIntent>  $intents
     * @return list<Movement> the rows written, in the order of $intents
     *
     * @throws InventoryError inventory.stock.insufficient / inventory.stock.reserved_below_zero (409), nothing written
     * @throws InvalidMovement an item or location outside the branch, or a transfer whose sides do not balance
     * @throws UniqueConstraintViolationException a client_uuid this branch already has (an offline record replayed)
     */
    public function appendMany(array $intents): array
    {
        if ($intents === []) {
            return [];
        }
        $userId = $this->actor->requireUserId();
        self::assertTransfersBalance($intents);

        $pairs = [];
        foreach ($intents as $intent) {
            $pairs[self::key($intent->itemId, $intent->locationId)] = [$intent->itemId, $intent->locationId];
            if ($intent->counterpartLocationId !== null) {
                $pairs[self::key($intent->itemId, $intent->counterpartLocationId)] = [$intent->itemId, $intent->counterpartLocationId];
            }
        }
        $branches = $this->branchesOf($pairs);

        /** @var array{0: list<StockMovement>, 1: array<string, StockBalance>, 2: array<string, list<int>>} $written */
        $written = $this->connection()->transaction(function () use ($intents, $pairs, $branches, $userId): array {
            // with the flag on: every on_hand change at a station that tracks shifts must be covered (and the shifts relied on are
            // locked) before any balance lock is taken; nothing is consulted with the flag off
            $stamps = $this->shiftCover($intents);
            $balances = $this->lockRows($pairs, $branches);

            // the batch's effect on every balance it touches, checked as a whole before anything is written
            $next = [];
            foreach ($intents as $intent) {
                $key = self::key($intent->itemId, $intent->locationId);
                $effect = $intent->effect();
                [$onHand, $reserved] = $next[$key] ?? [$balances[$key]->on_hand, $balances[$key]->reserved];
                $next[$key] = [$onHand->plus($effect->onHand), $reserved->plus($effect->reserved)];
            }
            ksort($next, SORT_NATURAL);
            foreach ($next as $key => [$onHand, $reserved]) {
                $balance = $balances[$key];
                if ($onHand->isNegative()) {
                    throw InventoryError::insufficientStock($balance->item_id, $balance->location_id, $balance->on_hand->toString(), $onHand->toString());
                }
                if ($reserved->isNegative()) {
                    throw InventoryError::reservedBelowZero($balance->item_id, $balance->location_id, $balance->reserved->toString(), $reserved->toString());
                }
            }

            $now = CarbonImmutable::now();
            $movements = [];
            $byPair = [];
            foreach ($intents as $index => $intent) {
                $row = new StockMovement;
                $row->forceFill([
                    'branch_id' => $branches[self::key($intent->itemId, $intent->locationId)],
                    'item_id' => $intent->itemId,
                    'from_location_id' => $intent->fromLocationId(),
                    'to_location_id' => $intent->toLocationId(),
                    'qty' => $intent->qty,
                    'reason' => $intent->reason,
                    'ref_type' => $intent->refType,
                    'ref_id' => $intent->refId,
                    'reservation_ref' => $intent->reservationRef,
                    'shift_instance_id' => $stamps[$index] ?? $this->actor->shiftInstanceId(),
                    'user_id' => $userId,
                    'acting_user_id' => $this->actor->actingUserId(),
                    'station_id' => $this->actor->stationId(),
                    'device_id' => $this->actor->deviceId(),
                    'client_uuid' => $intent->clientUuid,
                    'created_at' => $now,
                ]);
                $row->save();
                $movements[] = $row;
                $byPair[self::key($intent->itemId, $intent->locationId)][] = $row->id;
            }

            foreach ($next as $key => [$onHand, $reserved]) {
                $balances[$key]->forceFill(['on_hand' => $onHand, 'reserved' => $reserved])->save();
            }

            return [$movements, $balances, $byPair];
        }, self::ATTEMPTS);

        [$movements, $balances, $byPair] = $written;
        foreach ($byPair as $key => $ids) {
            $level = $balances[$key]->toLevel();
            $this->events->dispatch(new StockChanged(
                $balances[$key]->branch_id,
                $level->itemId,
                $level->locationId,
                $level->onHand->toString(),
                $level->reserved->toString(),
                $level->available()->toString(),
                $ids,
            ));
        }

        return array_map(static fn (StockMovement $m): Movement => $m->toMovement(), $movements);
    }

    /**
     * The shift check of ledger.require_shift_for_station_locations (phase-3 plan section 10.1), run inside the batch's
     * transaction before any balance lock. An intent needs cover when it changes on_hand (every reason but the two that move
     * reserved only), at a location whose station tracks shifts, and is not a count's own adjustment. It is covered when the
     * actor carries a shift that is open (the row keeps the actor's stamp), else when the station has a shift in status open
     * (the row is stamped with it). Every shift relied on is then re-read with a shared lock in ascending id order, so a close
     * (which takes the row FOR UPDATE) either finishes first or finds the movement written. Nothing runs with the flag off.
     *
     * @param  list<MovementIntent>  $intents
     * @return array<int, int> intent index => the shift to stamp when it is not the actor's own
     *
     * @throws InventoryError inventory.shift.required (409) naming the station and location of the first uncovered intent
     */
    private function shiftCover(array $intents): array
    {
        if (! (bool) config('ledger.require_shift_for_station_locations')) {
            return [];
        }

        $needing = [];
        foreach ($intents as $index => $intent) {
            $countsOwn = $intent->reason === MovementReason::CountAdjustment && $intent->refType === 'stock_count_line';
            if (! $countsOwn && ! $intent->effect()->onHand->isZero()) {
                $needing[$index] = $intent->locationId;
            }
        }
        if ($needing === []) {
            return [];
        }

        $locations = Location::query()->whereKey(array_values(array_unique($needing)))->with('station')->get()->keyBy('id');
        $tracked = [];
        foreach ($needing as $index => $locationId) {
            $station = $locations[$locationId]->station ?? null;
            if ($station !== null && $station->tracks_shifts) {
                $tracked[$index] = [$station->id, $locationId];
            }
        }
        if ($tracked === []) {
            return [];
        }

        $shifts = app(OpenShifts::class);
        $actorShift = $this->actor->shiftInstanceId();
        $actorCovers = $actorShift !== null && $shifts->isOpen($actorShift);

        $stamps = [];
        $relied = [];
        $found = [];
        foreach ($tracked as $index => [$stationId, $locationId]) {
            if ($actorCovers) {
                $relied[$actorShift] ??= [$stationId, $locationId];

                continue;
            }
            if (! array_key_exists($stationId, $found)) {
                $found[$stationId] = $shifts->openAt($stationId);
            }
            $shiftId = $found[$stationId] ?? throw InventoryError::shiftRequired($stationId, $locationId);
            $stamps[$index] = $shiftId;
            $relied[$shiftId] ??= [$stationId, $locationId];
        }

        ksort($relied);
        foreach ($relied as $shiftId => [$stationId, $locationId]) {
            if (! $shifts->isOpen($shiftId, true)) {
                throw InventoryError::shiftRequired($stationId, $locationId);
            }
        }

        return $stamps;
    }

    /**
     * Lock the balance rows of the given (item, location) pairs for the rest of the caller's transaction, in ascending
     * (item_id, location_id) order, creating a missing row at zero first. Returns the current levels in that order.
     *
     * @param  list<array{0: int, 1: int}>  $pairs  [item_id, location_id]
     * @return array<string, StockLevel> keyed by Ledger::key(), ascending
     *
     * @throws LogicException outside a transaction (a lock released at once protects nothing)
     */
    public function lock(array $pairs): array
    {
        if ($this->connection()->transactionLevel() === 0) {
            throw new LogicException('Ledger::lock() holds row locks for the rest of a transaction: call it inside one.');
        }
        $keyed = [];
        foreach ($pairs as [$itemId, $locationId]) {
            $keyed[self::key($itemId, $locationId)] = [$itemId, $locationId];
        }

        return array_map(
            static fn (StockBalance $b): StockLevel => $b->toLevel(),
            $this->lockRows($keyed, $this->branchesOf($keyed)),
        );
    }

    /**
     * Lock every stock_balances row of the given items, across all their locations, for the rest of the caller's
     * transaction, in ascending (item_id, location_id) order (the first half of the system-wide lock order: balances, then
     * item_costs ascending item_id), and answer each item's total on_hand over those locations. An item that never moved
     * has no row to lock and totals zero. The cost core reads the quantity the moving average is taken over through this
     * (Costs\CostLedger); reserved stock is on hand and counts. Nothing is written.
     *
     * @param  list<int>  $itemIds
     * @return array<int, Quantity> total on_hand per item id, ascending by item id
     *
     * @throws InvalidMovement an item that is not a live item of this branch
     * @throws LogicException outside a transaction (a lock released at once protects nothing)
     */
    public function lockItems(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        if ($this->connection()->transactionLevel() === 0) {
            throw new LogicException('Ledger::lockItems() holds row locks for the rest of a transaction: call it inside one.');
        }
        $ids = array_values(array_unique(array_map(static fn (int $id): int => $id, $itemIds)));
        sort($ids);

        $branches = Item::query()->whereKey($ids)->pluck('branch_id', 'id')->all();
        $current = $this->branch->idOrNull();
        foreach ($ids as $id) {
            if (! isset($branches[$id]) || ($current !== null && (int) $branches[$id] !== $current)) {
                throw InvalidMovement::because("item {$id} is not a live item of this branch");
            }
        }

        $totals = array_fill_keys($ids, Quantity::zero());
        $rows = StockBalance::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereIn('item_id', $ids)
            ->orderBy('item_id')
            ->orderBy('location_id')
            ->lockForUpdate()
            ->get();
        foreach ($rows as $row) {
            $totals[$row->item_id] = $totals[$row->item_id]->plus($row->on_hand);
        }

        return $totals;
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $pairs
     * @param  array<string, int>  $branches  branch id per pair
     * @return array<string, StockBalance> keyed by Ledger::key(), ascending (item_id, location_id)
     */
    private function lockRows(array $pairs, array $branches): array
    {
        uasort($pairs, static fn (array $a, array $b): int => $a <=> $b);

        // A plain read (no lock) tells which rows are missing. A missing row is NEVER locked with SELECT ... FOR UPDATE: on
        // InnoDB that locks the gap, gap locks do not conflict with each other, and two processes making the first movement
        // of the same item at the same place would each hold the gap and then wait for the other's INSERT (deadlock 1213).
        $existing = [];
        $itemIds = array_values(array_unique(array_map(static fn (array $p): int => $p[0], $pairs)));
        foreach (StockBalance::query()->withoutGlobalScope(BranchScope::class)->whereIn('item_id', $itemIds)->get(['item_id', 'location_id']) as $row) {
            $existing[self::key($row->item_id, $row->location_id)] = true;
        }

        $locked = [];
        foreach ($pairs as $key => [$itemId, $locationId]) {
            if (! isset($existing[$key])) {
                // First movement of this item here: create the row at zero, in the lock order, before locking it. The upsert
                // takes the row's exclusive lock whether it inserts or finds a row another process just committed (an
                // INSERT IGNORE would take a shared lock on the duplicate, and two shared locks cannot both upgrade). The
                // update touches no value, so a concurrent creator wins harmlessly.
                StockBalance::query()->withoutGlobalScope(BranchScope::class)->toBase()->upsert([
                    'branch_id' => $branches[$key],
                    'item_id' => $itemId,
                    'location_id' => $locationId,
                    'on_hand' => Quantity::zero()->toString(),
                    'reserved' => Quantity::zero()->toString(),
                    'updated_at' => CarbonImmutable::now(),
                ], ['item_id', 'location_id'], ['branch_id']);
            }
            $locked[$key] = $this->balanceForUpdate($itemId, $locationId)
                ?? throw new LogicException("stock_balances row {$key} could not be created.");
        }

        return $locked;
    }

    private function balanceForUpdate(int $itemId, int $locationId): ?StockBalance
    {
        return StockBalance::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The branch of every pair: item and location must exist, be live, belong to the same branch, and to the resolved
     * CurrentBranch when there is one (rule 8).
     *
     * @param  array<string, array{0: int, 1: int}>  $pairs
     * @return array<string, int>
     */
    private function branchesOf(array $pairs): array
    {
        $itemIds = array_values(array_unique(array_map(static fn (array $p): int => $p[0], $pairs)));
        $locationIds = array_values(array_unique(array_map(static fn (array $p): int => $p[1], $pairs)));
        $items = Item::query()->whereKey($itemIds)->pluck('branch_id', 'id')->all();
        $locations = Location::query()->whereKey($locationIds)->pluck('branch_id', 'id')->all();
        $current = $this->branch->idOrNull();

        $branches = [];
        foreach ($pairs as $key => [$itemId, $locationId]) {
            $itemBranch = $items[$itemId] ?? null;
            $locationBranch = $locations[$locationId] ?? null;
            if ($itemBranch === null) {
                throw InvalidMovement::because("item {$itemId} is not a live item of this branch");
            }
            if ($locationBranch === null) {
                throw InvalidMovement::because("location {$locationId} is not a live location of this branch");
            }
            if ((int) $itemBranch !== (int) $locationBranch || ($current !== null && (int) $itemBranch !== $current)) {
                throw InvalidMovement::because("item {$itemId} and location {$locationId} belong to different branches");
            }
            $branches[$key] = (int) $itemBranch;
        }

        return $branches;
    }

    /**
     * A transfer moves stock, it never creates or destroys it: within a batch the transfer rows of each item and pair
     * of ends must add up to zero.
     *
     * @param  list<MovementIntent>  $intents
     */
    private static function assertTransfersBalance(array $intents): void
    {
        $sums = [];
        foreach ($intents as $intent) {
            if ($intent->reason !== MovementReason::Transfer || $intent->counterpartLocationId === null) {
                continue;
            }
            $ends = [$intent->locationId, $intent->counterpartLocationId];
            sort($ends);
            $key = $intent->itemId.':'.implode('-', $ends);
            $sums[$key] = ($sums[$key] ?? Quantity::zero())->plus($intent->qty);
        }
        foreach ($sums as $key => $sum) {
            if (! $sum->isZero()) {
                throw InvalidMovement::because("the transfer sides of {$key} do not balance (net {$sum}); write both with MovementIntent::transfer()");
            }
        }
    }

    private function connection(): Connection
    {
        $connection = $this->db->connection();
        if (! $connection instanceof Connection) {
            throw new LogicException('The Ledger needs a database connection.');
        }

        return $connection;
    }
}
