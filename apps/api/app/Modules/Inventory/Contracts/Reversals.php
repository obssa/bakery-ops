<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Inventory\Exceptions\InventoryError;
use App\Modules\Inventory\Ledger\InvalidMovement;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\LedgerReader;

/**
 * manifest inventory.provides, Phase 4: "Reversals::of(movementIds)" (step 4.1b, phase-4 plan section 2; contract orders->inventory cases
 * 13 to 15; Ramin's answer to R-C, 2026-10-28). How a refund puts units back into stock without touching what was written:
 *   of(ids): for each original sale or production_consume, ONE `return` of the opposite quantity at the place the original changed,
 *     pointing at it (ref_type `stock_movement`, ref_id the original) with its stock_movement_reversals row, in one Ledger batch (the
 *     shift rule of the place, ascending balance locks, every row or none; Ledger::reverse). Refused, nothing written: an id that is not a
 *     movement of this branch (inventory.reversal.not_found, 404), a reason other than sale / production_consume
 *     (inventory.reversal.not_reversible, 422), an original reversed already (inventory.reversal.reversed, 409; also when a concurrent
 *     reversal committed first: two unique keys decide), no open shift at a tracked station (inventory.shift.required, 409);
 *   consumptionsOf(ref, item): the sale and production_consume rows of an item that carry a reservation ref, oldest first: what an order
 *     line took out of stock of its own product (a display-case sale, or the product itself used at a station);
 *   reversed(ids): which of the ids a reversal names already, original id => its return's id;
 *   returns(ids): the `return` movements among the ids, ascending (step 4.3d: a refund's answer reads the returns it wrote; each refId is
 *     the original it reverses).
 * Resolved per use: the Ledger carries the request-scoped ActorContext and CurrentBranch (traps.md, Octane).
 */
final class Reversals
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly LedgerReader $reader,
    ) {}

    /**
     * @param  list<int>  $movementIds
     * @return list<Movement> the returns written, in the order of $movementIds (a repeated id once)
     *
     * @throws InventoryError
     * @throws InvalidMovement an original of an item that is no longer live
     */
    public function of(array $movementIds): array
    {
        return $this->ledger->reverse($movementIds);
    }

    /**
     * @return list<Movement>
     */
    public function consumptionsOf(string $reservationRef, int $itemId): array
    {
        return $this->reader->consumptionsOf($reservationRef, $itemId);
    }

    /**
     * @param  list<int>  $movementIds
     * @return list<Movement> the returns among the ids, ascending by id; another branch's or a movement that is not a return is left out
     */
    public function returns(array $movementIds): array
    {
        return $this->reader->returnsOf($movementIds);
    }

    /**
     * @param  list<int>  $movementIds
     * @return array<int, int> original id => the id of the return that reverses it, ascending
     */
    public function reversed(array $movementIds): array
    {
        return $this->reader->reversalsOf($movementIds);
    }
}
