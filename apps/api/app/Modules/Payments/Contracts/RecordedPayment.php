<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Orders\Contracts\OrderPaymentView;
use App\Modules\Payments\Models\Payment;

/**
 * What Payments::record answers: the payment, whether this call wrote it (false when its client UUID had been used
 * already and this is that payment again), and the order as it stands after it (status, settled, totals).
 */
final readonly class RecordedPayment
{
    public function __construct(
        public Payment $payment,
        public bool $created,
        public OrderPaymentView $order,
    ) {}
}
