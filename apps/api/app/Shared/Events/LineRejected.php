<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * A station gave a line back (-> rejected, with a reason code of the fixed list; its reservation was released in the
 * same transaction, ADR 0008): the owner's pad shows it as unfulfilled (plan section 5; Phase 2 adds a notification).
 */
final class LineRejected extends OrderLineEvent
{
    public function __construct(
        int $branchId,
        int $lineId,
        int $orderId,
        ?int $stationId,
        int $byUserId,
        int $ownerUserId,
        public readonly string $reason,
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
        return parent::payload() + ['reason' => $this->reason];
    }
}
