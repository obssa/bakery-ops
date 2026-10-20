<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use Carbon\CarbonImmutable;

/**
 * An order that was open at a moment, as seen from one station (Orders::openAt): its header and the lines it had at
 * that station, each with its status as of that moment.
 */
final readonly class OpenOrderView
{
    /**
     * @param  list<OpenLineView>  $lines  the order's lines at the station asked about, oldest first
     */
    public function __construct(
        public int $orderId,
        public ?int $orderNo,
        public string $type,
        public ?string $tableCode,
        public int $ownerUserId,
        public CarbonImmutable $sentAt,
        public array $lines,
    ) {}
}
