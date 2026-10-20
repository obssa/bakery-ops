<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * The reservation of an order line (ADR 0008 decision 2): one per line, the UUIDv5 of "order-line:{branch_id}:{client
 * UUID}" (lower case), so an offline replay of the same line can only ever reach the same reservation, and two branches
 * that share a client UUID never share one. Pinned by tests/Unit/Orders/ReservationRefTest.php: a change here would
 * orphan every reservation already in the ledger.
 */
final class ReservationRef
{
    public static function forLine(int $branchId, string $clientUuid): string
    {
        if (! Str::isUuid($clientUuid)) {
            throw new InvalidArgumentException('An order line client UUID is a UUID.');
        }

        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'order-line:'.$branchId.':'.strtolower($clientUuid))->toString();
    }
}
