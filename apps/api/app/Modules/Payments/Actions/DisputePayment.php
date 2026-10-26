<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\PaymentLock;
use App\Shared\Events\PaymentDisputed;
use App\Shared\StateMachine\IllegalTransition;
use Carbon\CarbonImmutable;

/**
 * POST /payments/{payment}/dispute (phase-4 plan section 4.4, step 4.3c; payments.verify): the payment could not be found on the bank's statement.
 * recorded -> disputed, with the reason (3 to 255 characters, the request's rule) and who disputed it; any other status is 409
 * state_machine.illegal_transition (a verified payment is not disputed again; a disputed one is resolved by verify or void). A disputed payment is
 * still money received: it counts in the totals and the drawer, and the lists flag it (10.8 R-Q). One AuditedAction (payment.dispute) under the
 * payment's row lock; PaymentDisputed is announced after the commit with the order's number and the channel's names, so Notifications can tell the
 * other approvers without reading Payments or Orders.
 */
final class DisputePayment
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly Orders $orders,
    ) {}

    /**
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    public function handle(Payment $payment, User $by, string $reason): Payment
    {
        $payment->status->transitionTo(PaymentStatus::Disputed);

        $payment = $this->audit->run(function (Payment $payment) use ($by, $reason): Payment {
            PaymentLock::refresh($payment);
            $payment->forceFill([
                'status' => $payment->status->transitionTo(PaymentStatus::Disputed),
                'disputed_by_user_id' => $by->id,
                'disputed_at' => CarbonImmutable::now()->startOfSecond(),
                'dispute_reason' => $reason,
            ])->save();

            return $payment;
        }, $payment, 'payment.dispute', ['reason' => $reason, 'order_id' => $payment->order_id]);

        // a payment's order was sent, so it has a number; 0 only if the order could not be read at all
        $orderNo = ($this->orders->summaries([$payment->order_id])[$payment->order_id] ?? null)->orderNo ?? 0;
        $channel = $payment->channel;
        event(new PaymentDisputed(
            $payment->branch_id,
            $payment->id,
            $payment->order_id,
            $orderNo,
            $channel->code,
            $channel->name_en,
            $channel->name_am,
            $payment->amount->amount(),
            $reason,
            $by->id,
        ));

        return $payment;
    }
}
