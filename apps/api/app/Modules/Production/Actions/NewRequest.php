<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Production\Enums\RequestPriority;
use App\Modules\Production\Enums\RequestReason;
use App\Shared\Values\Quantity;
use DateTimeInterface;

/**
 * What a request is asked to be, before CreateRequest checks it: the client UUID that makes a replay safe, the product by id,
 * the quantity in its base unit (null asks for the batch size), the due time, the priority, why, an optional note, and for an
 * automatic request the stock_alert_states episode that caused it.
 */
final readonly class NewRequest
{
    public function __construct(
        public string $clientUuid,
        public int $product,
        public Quantity|string|null $qty,
        public ?DateTimeInterface $due,
        public RequestReason $reason,
        public RequestPriority $priority = RequestPriority::Normal,
        public ?string $note = null,
        public ?int $sourceAlertId = null,
    ) {}
}
