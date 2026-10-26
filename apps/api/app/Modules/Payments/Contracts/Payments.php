<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Identity\Models\User;
use App\Modules\Payments\Actions\RecordPayment;
use App\Modules\Payments\Actions\RecordPaymentBatch;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\Payment;
use App\Shared\Live\OverlapCursor;
use Carbon\CarbonImmutable;

/**
 * What other modules may ask of Payments (manifest payments.provides): take a payment, take a split bill, and the cheap state of the
 * branch's payments that the polled lists are tagged with. The HTTP endpoints are thin callers of it; Phase 3 (the cash count) and
 * Phase 4 (every channel, refunds) build on the same calls. Resolved per use, never held (it reads the request's branch and actor).
 */
final class Payments
{
    public function __construct(
        private readonly RecordPayment $record,
        private readonly RecordPaymentBatch $batch,
    ) {}

    /**
     * Record one payment as one AuditedAction in one transaction: the order is locked, the payment row written, its receipt photo
     * attached, PaymentRecorded dispatched inside the transaction (Orders adds it to paid_total and, once the balance is zero and the order
     * served, pays, sells and closes it), all committed together or not at all. From step 4.3b any enabled channel is taken, with its
     * photo (receipt_file_id) and reference when the channel asks for them, and the amount may be a part of the balance. Amounts are
     * decimal strings. A client UUID already used answers that payment again (created false) and writes nothing.
     *
     * @param  string|null  $tenderedAmount  cash handed over (cash only; null means exactly the amount)
     *
     * @throws PaymentError payments.channel.* (422), payments.photo.invalid (422), payments.reference.invalid (422),
     *                      payments.shift.required (409), payments.order.not_payable (409), payments.order.settled (409),
     *                      payments.amount.mismatch (422), payments.tendered.too_low (422), payments.client_uuid.reused (409)
     */
    public function record(
        int $orderId,
        string $clientUuid,
        string $channelCode,
        string $amount,
        ?string $tenderedAmount,
        ?string $referenceNo,
        User $receivedBy,
        ?int $receiptFileId = null,
    ): RecordedPayment {
        return $this->record->handle($orderId, $clientUuid, $channelCode, $amount, $tenderedAmount, $referenceNo, $receivedBy, $receiptFileId);
    }

    /**
     * Record a split bill (step 4.3b): 1 to 6 payments whose amounts add up to exactly the balance, all or none, as one AuditedAction
     * (payment.batch) in one transaction, every row carrying $batchUuid. A row that would be refused on its own refuses the batch
     * (payments.batch.row_invalid); a $batchUuid already used answers that batch again (created false).
     *
     * @param  list<PaymentInput>  $rows
     *
     * @throws PaymentError payments.batch.sum_mismatch (422), payments.batch.row_invalid (422), and the order's and the till's refusals of record()
     */
    public function recordBatch(int $orderId, string $batchUuid, array $rows, User $receivedBy): RecordedBatch
    {
        return $this->batch->handle($orderId, $batchUuid, $rows, $receivedBy);
    }

    /**
     * What a polled list of the branch's payments is tagged with (GET /payments; ADR 0009): how many payments there are, the highest id,
     * the latest change, and `settling`, the current second while that change is younger than the overlap window. Ids become visible in
     * commit order, not in id order, and updated_at has whole seconds, so a payment committed late, or two changes in one second, would
     * not move count, max id or max updated_at in time; while the newest change is young the tag moves every second, and once it is older
     * the tag is stable and different from every tag the window handed out (the lesson of Stock::fingerprint). One indexed query.
     *
     * @return array{count: int, max_id: int|null, max_updated_at: string|null, settling: int|null}
     */
    public function fingerprint(): array
    {
        $row = Payment::query()->toBase()->selectRaw('count(*) as n, max(id) as max_id, max(updated_at) as touched')->first();
        $touched = is_string($row?->touched) ? $row->touched : null;
        $now = CarbonImmutable::now();
        $young = $touched !== null
            && CarbonImmutable::parse($touched, 'UTC')->greaterThan($now->subSeconds(OverlapCursor::fromConfig()->windowSeconds()));

        return [
            'count' => (int) ($row->n ?? 0),
            'max_id' => is_numeric($row?->max_id) ? (int) $row->max_id : null,
            'max_updated_at' => $touched,
            'settling' => $young ? $now->getTimestamp() : null,
        ];
    }
}
