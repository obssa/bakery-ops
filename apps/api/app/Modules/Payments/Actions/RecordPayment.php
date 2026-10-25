<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Contracts\PaymentInput;
use App\Modules\Payments\Contracts\RecordedPayment;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\Duplicates;
use App\Modules\Payments\Support\PaymentChecks;
use App\Modules\Payments\Support\PaymentWriter;
use App\Shared\Values\Money;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * POST /orders/{order}/payments (phase-1 plan sections 4, 5 and 10; phase-4 plan section 4.3, step 4.3b; manifest contract
 * payments->orders). In order:
 *   0. another branch's or an unknown order is a 404; a client UUID already used answers that payment again (ADR 0003: a replay with a
 *      lost Idempotency-Key), 409 payments.client_uuid.reused when it belongs to another order;
 *   1-3. PaymentChecks, before anything is locked: the channel (unknown, disabled), the receipt photo (photo_required, photo.invalid),
 *      the reference (reference_required, reference.invalid);
 *   then ONE AuditedAction (payment.record) = one transaction: Orders::forPayment locks the order row and
 *   4. the shift rule, for every channel (Support\ShiftRule; 409 payments.shift.required);
 *   5. the order's state: sent .. served (409 payments.order.not_payable) and not settled yet (409 payments.order.settled);
 *   6. 0 < amount <= balance: a part payment is taken (step 4.3b; Phase 1 took only the whole balance), more is 422
 *      payments.amount.mismatch {balance, amount}; cash tendered must cover the amount (422 payments.tendered.too_low), change =
 *      tendered - amount;
 *   the photo's row is locked and checked again (another payment may have claimed it), the payment row is written, the photo attached
 *   (attached_* and receipt_photo_key), and PaymentRecorded is dispatched INSIDE the transaction, where Orders' ApplyPayment adds it to the
 *   order and, once the balance reaches zero, settles it, and when it is served pays, sells and closes it. A failure anywhere rolls the
 *   payment and the attach back with it.
 * The answer carries the duplicate warnings (Support\Duplicates), never a refusal. The reference number, its normalised form and the
 * photo's key are stored but never logged (the audit snapshot redacts them). Money is Money (brick/math) and decimal strings, never float.
 */
final class RecordPayment
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly Orders $orders,
        private readonly PaymentChecks $checks,
        private readonly PaymentWriter $writer,
        private readonly Duplicates $duplicates,
    ) {}

    /**
     * @throws PaymentError
     */
    public function handle(
        int $orderId,
        string $clientUuid,
        string $channelCode,
        string $amount,
        ?string $tenderedAmount,
        ?string $referenceNo,
        User $receivedBy,
        ?int $receiptFileId = null,
    ): RecordedPayment {
        $this->orders->forPayment($orderId); // another branch's or an unknown order is a 404 before anything else

        $existing = $this->existing($clientUuid, $orderId);
        if ($existing instanceof Payment) {
            return $this->replay($existing);
        }

        $checked = $this->checks->check(new PaymentInput($clientUuid, $channelCode, $amount, $tenderedAmount, $referenceNo, $receiptFileId));

        try {
            $payment = $this->audit->run(function () use ($orderId, $clientUuid, $checked, $receivedBy): Payment {
                $view = $this->orders->forPayment($orderId, true);
                $replayed = $this->existing($clientUuid, $orderId);
                if ($replayed instanceof Payment) {
                    throw new AlreadyRecorded($replayed);
                }
                $this->writer->assertShift([$checked]);
                if (! $view->isPayable()) {
                    throw PaymentError::notPayable($view->status);
                }
                if ($view->settled) {
                    throw PaymentError::settled();
                }

                $currency = $view->totals->currency;
                $money = Money::of($checked->input->amount, $currency);
                $balance = Money::of($view->totals->balance, $currency);
                if (! $money->isPositive() || $money->isGreaterThan($balance)) {
                    throw PaymentError::amountMismatch($balance->amount(), $money->amount());
                }
                $tender = $this->writer->tender($checked, $money, $currency);
                $photo = $this->writer->lockedPhoto($checked, $this->writer->lockPhotos([$checked]));

                return $this->writer->write($checked, $orderId, $money, $tender, $currency, $balance->minus($money), null, $receivedBy, $photo);
            }, null, 'payment.record', ['order_id' => $orderId, 'channel_code' => $checked->channel->code]);
        } catch (AlreadyRecorded $again) {
            return $this->replay($again->payment);
        } catch (UniqueConstraintViolationException $e) {
            // the same payment arrived twice at once under different keys: the other request wrote it
            $existing = $this->existing($clientUuid, $orderId);
            if (! $existing instanceof Payment) {
                throw $e;
            }

            return $this->replay($existing);
        }

        return new RecordedPayment($payment, true, $this->orders->forPayment($orderId), $this->duplicates->warnings([$payment]));
    }

    /**
     * @throws PaymentError payments.client_uuid.reused (409) when the UUID belongs to another order
     */
    private function existing(string $clientUuid, int $orderId): ?Payment
    {
        $payment = Payment::query()->where('client_uuid', $clientUuid)->first();
        if ($payment instanceof Payment && $payment->order_id !== $orderId) {
            throw PaymentError::clientUuidReused();
        }

        return $payment;
    }

    private function replay(Payment $payment): RecordedPayment
    {
        return new RecordedPayment($payment, false, $this->orders->forPayment($payment->order_id), $this->duplicates->warnings([$payment]));
    }
}
