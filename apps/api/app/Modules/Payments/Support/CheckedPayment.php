<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Contracts\PaymentInput;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Files\StoredFile;

/**
 * A payment that passed the checks made before the order is locked (PaymentChecks: the channel, the photo, the reference): the channel it
 * is taken on, the reference as it will be stored (trimmed, null when blank) and its normalised form, and the receipt photo, if any.
 */
final readonly class CheckedPayment
{
    public function __construct(
        public PaymentInput $input,
        public PaymentChannel $channel,
        public ?string $referenceNo,
        public ?string $referenceNorm,
        public ?StoredFile $file,
    ) {}
}
