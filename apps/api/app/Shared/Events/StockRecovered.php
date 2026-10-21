<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A stock alert episode is over: available rose back above the level that opened it (phase-2 plan section 5; ADR 0011).
 * Dispatched once, when Inventory closes the stock_alert_states row. It is what re-arms the alert: Notifications clears
 * its own delivery gate on it, so the next fall to 0 (or to the minimum) is announced again.
 */
final class StockRecovered extends DomainEvent implements ShouldDispatchAfterCommit
{
    /**
     * @param  string  $type  the episode that closed: out | low
     */
    public function __construct(
        int $branchId,
        public readonly int $itemId,
        public readonly int $locationId,
        public readonly string $type,
        public readonly int $alertId,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{item_id: int, location_id: int, type: string, alert_id: int}
     */
    public function payload(): array
    {
        return [
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'type' => $this->type,
            'alert_id' => $this->alertId,
        ];
    }
}
