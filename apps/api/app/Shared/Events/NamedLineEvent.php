<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * An order-line event that a person is told about in words (LineReady, LineRejected; phase-2 plan section 5, provider
 * change F5): besides the ids it carries the order's number, its table (null for a counter or take-away order) and the
 * item's name in both languages, so Notifications can write "Order 12, table T4: Chicken sandwich is ready" from the
 * payload alone, without importing Orders or Catalog. The new keys are additive: the existing broadcast companion sends
 * the same array and the pad ignores what it does not use.
 */
abstract class NamedLineEvent extends OrderLineEvent
{
    public function __construct(
        int $branchId,
        int $lineId,
        int $orderId,
        ?int $stationId,
        int $byUserId,
        int $ownerUserId,
        public readonly ?int $orderNo,
        public readonly ?string $tableCode,
        public readonly string $itemNameEn,
        public readonly string $itemNameAm,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $lineId, $orderId, $stationId, $byUserId, $ownerUserId, $occurredAt);
    }

    public function tellsOwner(): bool
    {
        return true;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function payload(): array
    {
        return parent::payload() + [
            'order_no' => $this->orderNo,
            'table_code' => $this->tableCode,
            'item_name_en' => $this->itemNameEn,
            'item_name_am' => $this->itemNameAm,
        ];
    }
}
