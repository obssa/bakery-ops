<?php

declare(strict_types=1);

use App\Modules\Production\Support\BatchMovementUuid;
use Ramsey\Uuid\Uuid;

pest()->group('unit', 'module:production');

/*
 * The client_uuid of every movement a batch writes (phase-2 plan section 4): the UUIDv5 of
 * "production-batch:{branch_id}:{batch client_uuid}:{role}" (lower case; role consume:{n} for the n-th recipe line from 0, or
 * output). The ledger's unique (branch_id, client_uuid) then lets a replay or a second confirm of one batch write nothing
 * twice, and two branches that reuse a uuid never share a row. Pinned here: a change would let a replay write a second set.
 */

it('derives stable uuids from the branch, the batch and the role', function () {
    $batch = '5d1b7c52-3f0e-4a4e-9a4e-0f6f3d6a8b11';

    expect(BatchMovementUuid::consume(3, $batch, 0))->toBe(Uuid::uuid5(Uuid::NAMESPACE_URL, 'production-batch:3:'.$batch.':consume:0')->toString())
        ->and(BatchMovementUuid::consume(3, strtoupper($batch), 1))->toBe(Uuid::uuid5(Uuid::NAMESPACE_URL, 'production-batch:3:'.$batch.':consume:1')->toString())
        ->and(BatchMovementUuid::output(3, $batch))->toBe(Uuid::uuid5(Uuid::NAMESPACE_URL, 'production-batch:3:'.$batch.':output')->toString())
        ->and(BatchMovementUuid::consume(4, $batch, 0))->not->toBe(BatchMovementUuid::consume(3, $batch, 0))
        ->and(BatchMovementUuid::consume(3, $batch, 1))->not->toBe(BatchMovementUuid::consume(3, $batch, 0))
        ->and(BatchMovementUuid::output(3, $batch))->not->toBe(BatchMovementUuid::consume(3, $batch, 0))
        ->and(Uuid::isValid(BatchMovementUuid::output(3, $batch)))->toBeTrue();
});
