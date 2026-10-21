<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;

/**
 * A station gave a line back (-> rejected, with a reason code of the fixed list; its reservation was released in the
 * same transaction, ADR 0008): the owner's pad shows it as unfulfilled and Notifications tells the owner (plan section 5).
 * Besides the NamedLineEvent keys it carries the reason code, the reason's text in both languages and the note the
 * station wrote (required for `other`), so the inbox line says why without importing Orders.
 */
final class LineRejected extends NamedLineEvent
{
    public function __construct(
        int $branchId,
        int $lineId,
        int $orderId,
        ?int $stationId,
        int $byUserId,
        int $ownerUserId,
        ?int $orderNo,
        ?string $tableCode,
        string $itemNameEn,
        string $itemNameAm,
        public readonly string $reason,
        public readonly string $reasonMessageEn,
        public readonly string $reasonMessageAm,
        public readonly ?string $note,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $lineId, $orderId, $stationId, $byUserId, $ownerUserId, $orderNo, $tableCode, $itemNameEn, $itemNameAm, $occurredAt);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function payload(): array
    {
        return parent::payload() + [
            'reason' => $this->reason,
            'reason_message_en' => $this->reasonMessageEn,
            'reason_message_am' => $this->reasonMessageAm,
            'note' => $this->note,
        ];
    }
}
