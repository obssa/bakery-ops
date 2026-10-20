<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Contracts\RecordedPayment;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Modules\Payments\Support\CashTender;
use App\Shared\Actor\ActorContext;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * POST /orders/{order}/payments (phase-1 plan sections 4, 5 and 10; manifest contract payments->orders). Phase 1 takes
 * one cash payment for the whole balance. In order:
 *   1. a client UUID already used answers that payment again (ADR 0003: a replay with a lost Idempotency-Key), 409
 *      payments.client_uuid.reused when it belongs to another order;
 *   2. the channel: it exists, is enabled, needs no receipt photo (every digital channel does, until Phase 4) and, when
 *      it asks for a reference, has one;
 *   3. ONE AuditedAction (payment.record) = one transaction: Orders::forPayment locks the order row; the order must be
 *      sent .. served (409 payments.order.not_payable) and not settled yet (409 payments.order.settled); the amount must
 *      be exactly the balance (422 payments.amount.mismatch: no part payment, no overpayment); cash tendered must cover
 *      it (422 payments.tendered.too_low), change = tendered - amount; the payment row is written, then PaymentRecorded
 *      is dispatched INSIDE the transaction, where Orders' ApplyPayment adds it to the order, settles it and, once it is
 *      served, pays, sells and closes it. A failure anywhere rolls the payment back with it.
 * The reference number is stored but never logged (the audit snapshot redacts it). Money is Money (brick/math) and
 * decimal strings, never float.
 */
final class RecordPayment
{
    /** The one channel whose money is counted in hand: it carries tendered cash and change. */
    private const string CASH = 'cash';

    public function __construct(
        private readonly AuditedAction $audit,
        private readonly Orders $orders,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @throws PaymentError
     */
    public function handle(int $orderId, string $clientUuid, string $channelCode, string $amount, ?string $tenderedAmount, ?string $referenceNo, User $receivedBy): RecordedPayment
    {
        $this->orders->forPayment($orderId); // another branch's or an unknown order is a 404 before anything else

        $existing = $this->existing($clientUuid, $orderId);
        if ($existing instanceof Payment) {
            return $this->replay($existing);
        }

        $referenceNo = $referenceNo === null || trim($referenceNo) === '' ? null : trim($referenceNo);
        $channel = $this->channel($channelCode, $referenceNo);

        try {
            $payment = $this->audit->run(function () use ($orderId, $clientUuid, $channel, $amount, $tenderedAmount, $referenceNo, $receivedBy): Payment {
                $view = $this->orders->forPayment($orderId, true);
                $replayed = $this->existing($clientUuid, $orderId);
                if ($replayed instanceof Payment) {
                    throw new AlreadyRecorded($replayed);
                }
                if (! $view->isPayable()) {
                    throw PaymentError::notPayable($view->status);
                }
                if ($view->settled) {
                    throw PaymentError::settled();
                }

                $currency = $view->totals->currency;
                $money = Money::of($amount, $currency);
                $balance = Money::of($view->totals->balance, $currency);
                if (! $money->isPositive() || ! $money->equals($balance)) {
                    throw PaymentError::amountMismatch($balance->amount(), $money->amount());
                }
                $tender = null;
                if ($channel->code === self::CASH) {
                    $tendered = $tenderedAmount === null ? null : Money::of($tenderedAmount, $currency);
                    if ($tendered instanceof Money && ! CashTender::covers($money, $tendered)) {
                        throw PaymentError::tenderedTooLow($money->amount(), $tendered->amount());
                    }
                    $tender = CashTender::of($money, $tendered);
                }

                $payment = new Payment;
                $payment->forceFill([
                    'branch_id' => $this->branch->id(),
                    'client_uuid' => $clientUuid,
                    'order_id' => $orderId,
                    'channel_id' => $channel->id,
                    'amount' => $money,
                    'tendered_amount' => $tender?->tendered,
                    'change_amount' => $tender?->change,
                    'currency' => $currency,
                    'reference_no' => $referenceNo,
                    'status' => PaymentStatus::Recorded,
                    'received_by_user_id' => $receivedBy->id,
                    'acting_user_id' => $this->actor->actingUserId(),
                    'shift_instance_id' => $this->actor->shiftInstanceId(),
                    'station_id' => $this->actor->stationId(),
                    'device_id' => $this->actor->deviceId(),
                    'received_at' => CarbonImmutable::now()->startOfSecond(),
                ])->save();

                event(new PaymentRecorded($this->branch->id(), $payment->id, $orderId, $channel->code, $money->amount(), $balance->minus($money)->amount()));

                return $payment;
            }, null, 'payment.record', ['order_id' => $orderId, 'channel_code' => $channel->code]);
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

        return new RecordedPayment($payment, true, $this->orders->forPayment($orderId));
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
        return new RecordedPayment($payment, false, $this->orders->forPayment($payment->order_id));
    }

    /**
     * @throws PaymentError payments.channel.unknown, .disabled, .photo_required, .reference_required (422)
     */
    private function channel(string $code, ?string $referenceNo): PaymentChannel
    {
        $channel = PaymentChannel::query()->where('code', $code)->first();
        if (! $channel instanceof PaymentChannel) {
            throw PaymentError::channelUnknown($code);
        }
        if (! $channel->is_enabled) {
            throw PaymentError::channelDisabled($code);
        }
        if ($channel->requires_photo) {
            throw PaymentError::photoRequired($code);
        }
        if ($channel->requires_reference && $referenceNo === null) {
            throw PaymentError::referenceRequired($code);
        }

        return $channel;
    }
}
