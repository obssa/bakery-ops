<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Actions\RecordPayment;
use App\Modules\Payments\Exceptions\PaymentError;

/**
 * What other modules may ask of Payments (manifest payments.provides): take a payment. The HTTP endpoint is a thin
 * caller of it; Phase 3 (the cash count) and Phase 4 (digital channels, refunds) build on the same call. Resolved per
 * use, never held (it reads the request's branch and actor).
 */
final class Payments
{
    public function __construct(private readonly RecordPayment $record) {}

    /**
     * Record one payment of the order's whole balance, as one AuditedAction in one transaction: the order is locked,
     * the payment row written, PaymentRecorded dispatched inside the transaction (Orders adds it to paid_total and,
     * once the order is served, pays, sells and closes it), all committed together or not at all. Amounts are decimal
     * strings. A client UUID already used answers that payment again (created false) and writes nothing.
     *
     * @param  string|null  $tenderedAmount  cash handed over (cash only; null means exactly the amount)
     *
     * @throws PaymentError payments.channel.* (422), payments.amount.mismatch (422), payments.tendered.too_low (422),
     *                      payments.order.not_payable (409), payments.order.settled (409), payments.client_uuid.reused (409)
     */
    public function record(
        int $orderId,
        string $clientUuid,
        string $channelCode,
        string $amount,
        ?string $tenderedAmount,
        ?string $referenceNo,
        User $receivedBy,
    ): RecordedPayment {
        return $this->record->handle($orderId, $clientUuid, $channelCode, $amount, $tenderedAmount, $referenceNo, $receivedBy);
    }
}
