<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Orders\Contracts\OrderPaymentView;
use App\Modules\Payments\Models\Payment;

/**
 * What Payments::record answers: the payment, whether this call wrote it (false when its client UUID had been used
 * already and this is that payment again), and the order as it stands after it (status, settled, totals). Step 4.3b adds the
 * warnings (a reference used before on the channel, a photo whose bytes are on another payment): never a refusal, each
 * {code, message_key, message_en, message_am, payment_id, payment_ids}.
 */
final readonly class RecordedPayment
{
    /**
     * @param  list<array<string, mixed>>  $warnings
     */
    public function __construct(
        public Payment $payment,
        public bool $created,
        public OrderPaymentView $order,
        public array $warnings = [],
    ) {}
}
