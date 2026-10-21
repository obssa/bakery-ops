<?php

declare(strict_types=1);

namespace App\Modules\Production\Support;

use Ramsey\Uuid\Uuid;

/**
 * The client_uuid of every movement a production batch writes (phase-2 plan section 4): the UUIDv5 of
 * "production-batch:{branch_id}:{batch client_uuid}:{role}" (batch uuid in lower case; role `consume:{n}` for the n-th line of
 * the scaled recipe counted from 0, or `output`). The ledger's unique (branch_id, client_uuid) index then lets a replay or a
 * second confirm of one batch write nothing twice, and two branches that reuse a uuid never share a row. Pinned by
 * tests/Unit/Production/BatchMovementUuidTest.php: a change here would let a replay write a second set.
 */
final class BatchMovementUuid
{
    public static function consume(int $branchId, string $batchUuid, int $line): string
    {
        return self::of($branchId, $batchUuid, 'consume:'.$line);
    }

    public static function output(int $branchId, string $batchUuid): string
    {
        return self::of($branchId, $batchUuid, 'output');
    }

    private static function of(int $branchId, string $batchUuid, string $role): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'production-batch:'.$branchId.':'.strtolower($batchUuid).':'.$role)->toString();
    }
}
