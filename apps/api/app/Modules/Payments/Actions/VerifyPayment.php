<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\PaymentLock;
use App\Shared\Events\PaymentVerified;
use App\Shared\StateMachine\IllegalTransition;
use Carbon\CarbonImmutable;

/**
 * POST /payments/{payment}/verify (phase-4 plan section 4.4, step 4.3c; payments.verify): the payment was found on the bank's statement.
 * recorded -> verified, or a dispute resolved (disputed -> verified, dispute_resolved_at; the dispute's columns stay as the record of it). A payment
 * that is verified already is a repeat: it is answered as it is and nothing is written or announced, also when another request verified it while
 * this one waited for the lock (AlreadyVerified). A voided payment is 409 state_machine.illegal_transition. One AuditedAction (payment.verify, the
 * optional note and the order in its context) under the payment's row lock; PaymentVerified is announced after the commit.
 */
final class VerifyPayment
{
    public function __construct(private readonly AuditedAction $audit) {}

    /**
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    public function handle(Payment $payment, User $by, ?string $note): Payment
    {
        if ($payment->status === PaymentStatus::Verified) {
            return $payment;
        }
        $payment->status->transitionTo(PaymentStatus::Verified);

        try {
            $payment = $this->audit->run(function (Payment $payment) use ($by): Payment {
                PaymentLock::refresh($payment);
                if ($payment->status === PaymentStatus::Verified) {
                    throw new AlreadyVerified($payment);
                }
                $from = $payment->status;
                $now = CarbonImmutable::now()->startOfSecond();
                $payment->forceFill([
                    'status' => $from->transitionTo(PaymentStatus::Verified),
                    'verified_by_user_id' => $by->id,
                    'verified_at' => $now,
                    'dispute_resolved_at' => $from === PaymentStatus::Disputed ? $now : $payment->dispute_resolved_at,
                ])->save();

                return $payment;
            }, $payment, 'payment.verify', ['note' => $note, 'order_id' => $payment->order_id]);
        } catch (AlreadyVerified $already) {
            return $already->payment;
        }

        event(new PaymentVerified($payment->branch_id, $payment->id, $payment->order_id, $payment->channel->code, $payment->amount->amount(), $by->id));

        return $payment;
    }
}
