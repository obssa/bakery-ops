<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Enums\OrderStatus;

/**
 * What GET /orders was asked, after the access rules: whose orders ($mine, forced for roles that do not see every
 * order), which status (null = active), unpaid, one table, the cursor of a poll and the size of a first page.
 */
final readonly class OrderListQuery
{
    public function __construct(
        public int $personId,
        public bool $mine,
        public ?OrderStatus $status,
        public bool $unpaid,
        public ?int $diningTableId,
        public ?int $since,
        public int $limit,
    ) {}
}
