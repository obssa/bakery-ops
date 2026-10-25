<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Orders\Contracts\OrderPaymentView;
use App\Modules\Payments\Models\Payment;

/**
 * What Payments::recordBatch answers (step 4.3b): the payments of the batch in the order of its rows, whether this call wrote them (false
 * when the batch's client UUID had been used and this is that batch again), the order as it stands after them, and the warnings of all
 * its rows (RecordedPayment).
 */
final readonly class RecordedBatch
{
    /**
     * @param  list<Payment>  $payments
     * @param  list<array<string, mixed>>  $warnings
     */
    public function __construct(
        public array $payments,
        public bool $created,
        public OrderPaymentView $order,
        public array $warnings = [],
    ) {}
}
