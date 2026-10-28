<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Orders\Contracts\OrderRefundView;
use App\Modules\Payments\Models\PaymentRefund;

/**
 * What Payments' refund (Actions\RefundPayments, step 4.3d) answers: the Orders-side refund as Orders::refund gave it, its money rows in
 * the order they were paid (one per payment it went back through), and whether this request wrote them (false: a refund already made
 * under the same client_uuid, answered as it was).
 */
final readonly class RefundOutcome
{
    /**
     * @param  list<PaymentRefund>  $rows
     */
    public function __construct(
        public OrderRefundView $refund,
        public array $rows,
        public bool $created,
    ) {}
}
