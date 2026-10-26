<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Models\Payment;
use RuntimeException;

/**
 * Thrown inside VerifyPayment's transaction when, once the payment is locked, a request that finished first turns out to have verified it already:
 * it rolls the transaction back (no audit row, no event), and the caller answers the payment as it is, the 200 of a repeat. Never leaves the
 * Payments module.
 */
final class AlreadyVerified extends RuntimeException
{
    public function __construct(public readonly Payment $payment)
    {
        parent::__construct('payment already verified');
    }
}
