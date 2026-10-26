<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Models\Payment;

/**
 * Inside a status action's transaction (step 4.3c: verify, dispute, void): lock the payment row (SELECT ... FOR UPDATE) and read it again, so two
 * decisions on one payment run one after the other and the second sees what the first did. The instance the action holds is brought up to date in
 * place, so the AuditedAction's "after" snapshot and the answer describe the row as it is. A void locks the order first (Orders::forPayment), then
 * the payment; verify and dispute lock only the payment, so no two of them ever wait for each other in opposite orders.
 */
final class PaymentLock
{
    public static function refresh(Payment $payment): Payment
    {
        $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
        $payment->setRawAttributes($locked->getAttributes(), true);

        return $payment;
    }
}
