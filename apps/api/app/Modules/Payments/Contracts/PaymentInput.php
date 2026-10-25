<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * One payment as the till asks for it (Payments::record, one row of Payments::recordBatch; step 4.3b): the client's id for it (ADR 0003),
 * the channel's code, the amount and the cash handed over as decimal strings, the bank's or wallet's reference, and the stored_files id
 * of the receipt photo. Nothing here is checked yet: RecordPayment checks it, in the order of phase-4 plan section 4.3.
 */
final readonly class PaymentInput
{
    public function __construct(
        public string $clientUuid,
        public string $channelCode,
        public string $amount,
        public ?string $tenderedAmount = null,
        public ?string $referenceNo = null,
        public ?int $receiptFileId = null,
    ) {}
}
