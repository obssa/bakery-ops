<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Models\Payment;
use RuntimeException;

/**
 * Thrown inside RecordPayment's transaction when, once the order is locked, its client UUID turns out to have been
 * recorded by a request that finished first: it rolls the (empty) transaction back, and the caller answers that payment
 * again. Never leaves the Payments module.
 */
final class AlreadyRecorded extends RuntimeException
{
    public function __construct(public readonly Payment $payment)
    {
        parent::__construct('payment already recorded');
    }
}
