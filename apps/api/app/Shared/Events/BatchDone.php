<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use InvalidArgumentException;

/**
 * A production batch was finished (phase-2 plan section 5, step 2.7c). `status` says which of two things happened:
 * `pending_confirmation` (the baker pressed Done at a station that needs an overseer: nothing is in the ledger yet) or
 * `confirmed` (the stock was written: on the same write as Done where no overseer is needed, or by the overseer's Confirm).
 * Notifications subscribes without importing Production: it asks the overseers and the production.confirm holders in the first
 * case, and tells the person who asked and the baker in the second, so both ids travel with the event (it may not read
 * Production's tables). `send_back_count` keys the "please sign off" line, so a batch finished again after a send back is heard
 * again while a redelivered event is not. Names in both languages, quantities as decimal strings in the product base unit.
 * Dispatched by Production's actions after their transaction commits; a repeat that writes nothing announces nothing.
 */
final class BatchDone extends DomainEvent implements ShouldDispatchAfterCommit
{
    public const string PENDING = 'pending_confirmation';

    public const string CONFIRMED = 'confirmed';

    public function __construct(
        int $branchId,
        public readonly int $batchId,
        public readonly int $requestId,
        public readonly string $status,
        public readonly int $productItemId,
        public readonly string $productNameEn,
        public readonly string $productNameAm,
        public readonly string $producedQty,
        public readonly string $wasteQty,
        public readonly string $unit,
        public readonly int $stationId,
        public readonly string $stationNameEn,
        public readonly string $stationNameAm,
        public readonly int $finishedByUserId,
        public readonly ?int $confirmedByUserId,
        public readonly int $requestedByUserId,
        public readonly ?int $assignedToUserId,
        public readonly int $sendBackCount,
        ?CarbonImmutable $occurredAt = null,
    ) {
        if (! in_array($status, [self::PENDING, self::CONFIRMED], true)) {
            throw new InvalidArgumentException("A BatchDone status is pending_confirmation or confirmed, got '{$status}'.");
        }
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'batch_id' => $this->batchId,
            'request_id' => $this->requestId,
            'status' => $this->status,
            'product_item_id' => $this->productItemId,
            'product_name_en' => $this->productNameEn,
            'product_name_am' => $this->productNameAm,
            'produced_qty' => $this->producedQty,
            'waste_qty' => $this->wasteQty,
            'unit' => $this->unit,
            'station_id' => $this->stationId,
            'station_name_en' => $this->stationNameEn,
            'station_name_am' => $this->stationNameAm,
            'finished_by_user_id' => $this->finishedByUserId,
            'confirmed_by_user_id' => $this->confirmedByUserId,
            'requested_by_user_id' => $this->requestedByUserId,
            'assigned_to_user_id' => $this->assignedToUserId,
            'send_back_count' => $this->sendBackCount,
        ];
    }
}
