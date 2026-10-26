<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\PaymentLock;
use App\Shared\Events\PaymentVoided;
use App\Shared\StateMachine\IllegalTransition;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;

/**
 * POST /payments/{payment}/void (phase-4 plan sections 4.4 and 10.4, step 4.3c): a wrong payment is voided before its order is paid, asked by a
 * person with orders.void_request and approved by the PIN of a payments.void holder (the controller verified it before this runs, so a wrong PIN
 * counts toward the lockout even though nothing is written; $approver is who the PIN belongs to; the PIN is never seen here, stored or logged).
 * Refusals, in order, before the PIN and again under the locks:
 *   - a payment the machine cannot void (it is voided already): 409 state_machine.illegal_transition;
 *   - a payment money already went back from (refunded_amount > 0, step 4.3d's refunds): 409 payments.void.refunded;
 *   - an order that is paid or closed: 409 payments.void.order_closed (the sale is finished: it is refunded instead, history is never rewritten).
 * Then ONE AuditedAction (payment.void: the actor is the asker, the approver and the order are in the context) = one transaction: the order row is
 * locked first (Orders::forPayment, the lock RecordPayment takes, so a void and a payment of one order run one after the other), then the payment's;
 * the payment goes voided with the reason and who asked (a disputed one is resolved by it), and PaymentVoided is dispatched INSIDE the transaction,
 * where Orders' ReversePayment takes the amount back out of paid_total, unsettles a settled order and writes payment.voided on the timeline. A
 * failure anywhere rolls the whole void back. Nothing was sold yet, so no stock moves.
 */
final class VoidPayment
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly Orders $orders,
    ) {}

    /**
     * The state a void can start from, so a refusal comes before a PIN attempt is spent.
     *
     * @throws IllegalTransition state_machine.illegal_transition (409)
     * @throws PaymentError payments.void.refunded (409), payments.void.order_closed (409)
     */
    public function assertVoidable(Payment $payment, ?string $orderStatus = null): void
    {
        $payment->status->transitionTo(PaymentStatus::Voided);
        $refunded = $payment->refunded_amount;
        if ($refunded instanceof Money && $refunded->isPositive()) {
            throw PaymentError::voidRefunded($refunded->amount());
        }
        $orderStatus ??= $this->orders->forPayment($payment->order_id)->status;
        if (in_array($orderStatus, ['paid', 'closed'], true)) {
            throw PaymentError::voidOrderClosed($orderStatus);
        }
    }

    /**
     * @throws IllegalTransition state_machine.illegal_transition (409)
     * @throws PaymentError payments.void.refunded (409), payments.void.order_closed (409)
     */
    public function handle(Payment $payment, User $requester, User $approver, string $reason): Payment
    {
        $this->assertVoidable($payment);

        return $this->audit->run(function (Payment $payment) use ($requester, $approver, $reason): Payment {
            $order = $this->orders->forPayment($payment->order_id, true);
            PaymentLock::refresh($payment);
            $this->assertVoidable($payment, $order->status);

            $from = $payment->status;
            $now = CarbonImmutable::now()->startOfSecond();
            $payment->forceFill([
                'status' => $from->transitionTo(PaymentStatus::Voided),
                'voided_by_user_id' => $requester->id,
                'voided_at' => $now,
                'void_reason' => $reason,
                'dispute_resolved_at' => $from === PaymentStatus::Disputed ? $now : $payment->dispute_resolved_at,
            ])->save();

            event(new PaymentVoided(
                $payment->branch_id,
                $payment->id,
                $payment->order_id,
                $payment->channel->code,
                $payment->amount->amount(),
                $reason,
                $requester->id,
                $approver->id,
            ));

            return $payment;
        }, $payment, 'payment.void', ['reason' => $reason, 'approved_by_user_id' => $approver->id, 'order_id' => $payment->order_id]);
    }
}
