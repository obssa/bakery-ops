<?php

declare(strict_types=1);

namespace App\Modules\Production\Support;

use Ramsey\Uuid\Uuid;

/**
 * The client_uuid of an automatic production request (phase-2 plan section 5): the UUIDv5 of "stock-out-alert:{branch_id}:
 * {alert_id}" (lower case), so a redelivered or racing job can only ever reach the same request and two branches that share an
 * alert number never share one. Pinned by tests/Unit/Production/AutoRequestUuidTest.php: a change here would let a replay make
 * a second request.
 */
final class AutoRequestUuid
{
    public static function forAlert(int $branchId, int $alertId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'stock-out-alert:'.$branchId.':'.$alertId)->toString();
    }
}
