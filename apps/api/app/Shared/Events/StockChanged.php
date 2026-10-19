<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The balance of one item at one location changed (manifest inventory.events; phase-0 plan section 5). Dispatched by
 * the Ledger once per changed (item, location) of a batch, only after the transaction commits, so no listener ever
 * sees stock that was rolled back. Listeners arrive in Phase 2 (reorder thresholds, StockLow / StockOut). Ids and
 * decimal strings only (DomainEvent), so subscribers never import Inventory.
 */
final class StockChanged extends DomainEvent implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<int>  $movementIds  the movements of the batch that changed this balance
     */
    public function __construct(
        int $branchId,
        public readonly int $itemId,
        public readonly int $locationId,
        public readonly string $onHand,
        public readonly string $reserved,
        public readonly string $available,
        public readonly array $movementIds,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{item_id: int, location_id: int, on_hand: string, reserved: string, available: string, movement_ids: list<int>}
     */
    public function payload(): array
    {
        return [
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'on_hand' => $this->onHand,
            'reserved' => $this->reserved,
            'available' => $this->available,
            'movement_ids' => $this->movementIds,
        ];
    }
}
