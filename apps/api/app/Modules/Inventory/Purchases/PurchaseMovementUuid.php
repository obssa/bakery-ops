<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchases;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * The client_uuid of the purchase_in movement a purchase line receives with (phase-2 plan section 10.3): the UUIDv5 of
 * "purchase-line:{branch_id}:{line client UUID}" (lower case), so a replayed confirm can only ever reach the same
 * movement and two branches that share a line UUID never share one. Pinned by tests/Modules/Inventory/Feature/
 * ConfirmPurchaseTest.php: a change here would let a replay receive twice.
 */
final class PurchaseMovementUuid
{
    public static function forLine(int $branchId, string $lineClientUuid): string
    {
        if (! Str::isUuid($lineClientUuid)) {
            throw new InvalidArgumentException('A purchase line client UUID is a UUID.');
        }

        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'purchase-line:'.$branchId.':'.strtolower($lineClientUuid))->toString();
    }
}
