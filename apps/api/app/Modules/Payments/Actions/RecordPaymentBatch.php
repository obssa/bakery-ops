<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Contracts\PaymentInput;
use App\Modules\Payments\Contracts\RecordedBatch;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\CheckedPayment;
use App\Modules\Payments\Support\Duplicates;
use App\Modules\Payments\Support\PaymentChecks;
use App\Modules\Payments\Support\PaymentWriter;
use App\Shared\Files\StoredFile;
use App\Shared\Values\Money;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * POST /orders/{order}/payment-batches (phase-4 plan section 4.3, step 4.3b): one bill split over channels, 1 to 6 payments, all or none.
 *   0. another branch's or an unknown order is a 404; the batch's client UUID (stored as every row's batch_uuid) already used answers that
 *      batch again, 409 payments.client_uuid.reused when it is another order's; a row's client UUID already used is 409 too (field
 *      rows.N.client_uuid): a batch is new or it is a replay, never half of each;
 *   1-3. each row through PaymentChecks; a refusal of row N is 422 payments.batch.row_invalid {row: N, error: the row's own refusal}, and
 *      one photo named by two rows is refused on the second (photo.invalid, attached);
 *   then ONE AuditedAction (payment.batch) = one transaction: Orders::forPayment locks the order row and
 *   4. the shift rule, once for the batch (409 payments.shift.required when any row needs the till's shift and it carries none open);
 *   5. the order's state (409 payments.order.not_payable, .settled);
 *   6. the rows' amounts (each above zero, the request's rule) must add up to exactly the balance (422 payments.batch.sum_mismatch
 *      {balance, sum}); each cash row's tender must cover its amount (row_invalid, payments.tendered.too_low);
 *   the photos' rows are locked in ascending id order and checked again (row_invalid when one was claimed meanwhile), then the payments
 *   are written in the order of the rows, each attaching its photo and dispatching PaymentRecorded with the balance after it, so Orders
 *   settles the order with the last one and sells its display-case lines once.
 * The answer carries the warnings of every row (Support\Duplicates).
 */
final class RecordPaymentBatch
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly Orders $orders,
        private readonly PaymentChecks $checks,
        private readonly PaymentWriter $writer,
        private readonly Duplicates $duplicates,
    ) {}

    /**
     * @param  list<PaymentInput>  $rows
     *
     * @throws PaymentError
     */
    public function handle(int $orderId, string $batchUuid, array $rows, User $receivedBy): RecordedBatch
    {
        $this->orders->forPayment($orderId); // another branch's or an unknown order is a 404 before anything else

        $existing = $this->existing($batchUuid, $orderId);
        if ($existing !== []) {
            return $this->replay($existing);
        }
        $this->assertRowsAreNew($rows);

        $checked = [];
        $claimed = [];
        foreach ($rows as $i => $row) {
            $checked[] = $this->forRow($i, fn (): CheckedPayment => $this->checks->check($row, $claimed));
            if ($row->receiptFileId !== null) {
                $claimed[] = $row->receiptFileId;
            }
        }

        try {
            $payments = $this->audit->run(function () use ($orderId, $batchUuid, $rows, $checked, $receivedBy): array {
                $view = $this->orders->forPayment($orderId, true);
                $replayed = $this->existing($batchUuid, $orderId);
                if ($replayed !== []) {
                    throw new AlreadyRecorded($replayed[0]);
                }
                $this->assertRowsAreNew($rows);
                $this->writer->assertShift($checked);
                if (! $view->isPayable()) {
                    throw PaymentError::notPayable($view->status);
                }
                if ($view->settled) {
                    throw PaymentError::settled();
                }

                $currency = $view->totals->currency;
                $balance = Money::of($view->totals->balance, $currency);
                $amounts = [];
                $sum = Money::zero($currency);
                foreach ($checked as $i => $row) {
                    $amounts[$i] = Money::of($row->input->amount, $currency);
                    $sum = $sum->plus($amounts[$i]);
                }
                if (! $sum->equals($balance)) {
                    throw PaymentError::batchSumMismatch($balance->amount(), $sum->amount());
                }
                $tenders = [];
                foreach ($checked as $i => $row) {
                    $tenders[$i] = $this->forRow($i, fn () => $this->writer->tender($row, $amounts[$i], $currency));
                }
                $locked = $this->writer->lockPhotos($checked);
                $photos = [];
                foreach ($checked as $i => $row) {
                    $photos[$i] = $this->forRow($i, fn (): ?StoredFile => $this->writer->lockedPhoto($row, $locked));
                }

                $written = [];
                $left = $balance;
                foreach ($checked as $i => $row) {
                    $left = $left->minus($amounts[$i]);
                    $written[] = $this->writer->write($row, $orderId, $amounts[$i], $tenders[$i], $currency, $left, $batchUuid, $receivedBy, $photos[$i]);
                }

                return $written;
            }, null, 'payment.batch', [
                'order_id' => $orderId,
                'batch_uuid' => $batchUuid,
                'rows' => array_map(fn (CheckedPayment $c): array => ['channel_code' => $c->channel->code, 'amount' => $c->input->amount], $checked),
            ]);
        } catch (AlreadyRecorded) {
            return $this->replay($this->existing($batchUuid, $orderId));
        } catch (UniqueConstraintViolationException $e) {
            // the same batch arrived twice at once under different keys: the other request wrote it
            $existing = $this->existing($batchUuid, $orderId);
            if ($existing === []) {
                throw $e;
            }

            return $this->replay($existing);
        }

        return new RecordedBatch($payments, true, $this->orders->forPayment($orderId), $this->duplicates->warnings($payments));
    }

    /**
     * Runs a check of row $i; a 422 refusal becomes the batch's payments.batch.row_invalid naming the row, anything else stays as it is.
     *
     * @template T
     *
     * @param  Closure(): T  $check
     * @return T
     *
     * @throws PaymentError
     */
    private function forRow(int $i, Closure $check): mixed
    {
        try {
            return $check();
        } catch (PaymentError $e) {
            throw $e->status === 422 ? PaymentError::batchRowInvalid($i, $e) : $e;
        }
    }

    /**
     * The payments of the batch, in the order of its rows.
     *
     * @return list<Payment>
     *
     * @throws PaymentError payments.client_uuid.reused (409) when the batch's UUID is another order's
     */
    private function existing(string $batchUuid, int $orderId): array
    {
        $payments = Payment::query()->where('batch_uuid', $batchUuid)->orderBy('id')->get()->all();
        foreach ($payments as $payment) {
            if ($payment->order_id !== $orderId) {
                throw PaymentError::clientUuidReused();
            }
        }

        return array_values($payments);
    }

    /**
     * @param  list<PaymentInput>  $rows
     *
     * @throws PaymentError payments.client_uuid.reused (409) naming the row whose UUID another payment has
     */
    private function assertRowsAreNew(array $rows): void
    {
        $uuids = array_map(fn (PaymentInput $row): string => $row->clientUuid, $rows);
        $used = Payment::query()->whereIn('client_uuid', $uuids)->pluck('client_uuid')->all();
        foreach ($rows as $i => $row) {
            if (in_array($row->clientUuid, $used, true)) {
                throw PaymentError::clientUuidReused("rows.{$i}.client_uuid");
            }
        }
    }

    /**
     * @param  list<Payment>  $payments
     */
    private function replay(array $payments): RecordedBatch
    {
        return new RecordedBatch($payments, false, $this->orders->forPayment($payments[0]->order_id), $this->duplicates->warnings($payments));
    }
}
