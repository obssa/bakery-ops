<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * An item is at or below the minimum its reorder rule sets (phase-2 plan section 5). Declared in step 2.1 with
 * the other stock alerts; Inventory dispatches it from step 2.6a, when the rule exists. Carries the rule it
 * crossed: the minimum, the quantity to reorder and the roles to tell (a list from the permission registry's roles
 * plus the literal "station", meaning the people assigned to the station of the place), and the code of the item's
 * base unit that every quantity is in, so the text can say "4000 g" without importing Catalog (step 2.6a).
 * For a branch-wide rule (a rule with no place) location_id is 0, the location names say "All places" and the
 * numbers are the sum over the branch's stock places.
 */
final class StockLow extends StockAlert
{
    /**
     * @param  list<string>  $notifyRoles
     */
    public function __construct(
        int $branchId,
        int $itemId,
        int $locationId,
        string $itemNameEn,
        string $itemNameAm,
        string $locationNameEn,
        string $locationNameAm,
        string $itemKind,
        ?string $fulfilment,
        string $available,
        string $onHand,
        string $reserved,
        int $alertId,
        ?int $causedByUserId,
        ?int $stationId,
        ?int $productionStationId,
        public readonly string $minQty,
        public readonly string $reorderQty,
        public readonly array $notifyRoles,
        ?CarbonImmutable $occurredAt = null,
        public readonly string $unit = '',
    ) {
        parent::__construct(
            $branchId, $itemId, $locationId, $itemNameEn, $itemNameAm, $locationNameEn, $locationNameAm,
            $itemKind, $fulfilment, $available, $onHand, $reserved, $alertId, $causedByUserId, $stationId, $productionStationId, $occurredAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return parent::payload() + [
            'min_qty' => $this->minQty,
            'reorder_qty' => $this->reorderQty,
            'notify_roles' => $this->notifyRoles,
            'unit' => $this->unit,
        ];
    }
}
