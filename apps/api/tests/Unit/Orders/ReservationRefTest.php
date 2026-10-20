<?php

declare(strict_types=1);

use App\Modules\Orders\Support\ReservationRef;

/*
 * One reservation per order line (ADR 0008 decision 2): order_lines.reservation_ref is the UUIDv5 of
 * "order-line:{branch_id}:{client_uuid}", so an offline replay of the same line can only ever reach the same
 * reservation, and two branches that happen to share a client UUID never share a reservation. The value is pinned
 * here: changing the derivation would orphan every reservation already in the ledger.
 */

it('derives the reservation ref of a line as a stable UUIDv5 of its branch and client UUID', function () {
    $ref = ReservationRef::forLine(7, '6f1c2e2a-3b4d-4c5e-8f90-a1b2c3d4e5f6');

    expect($ref)->toBe('637e751d-85fd-5f62-abfb-8eedafc6d205')
        ->and(substr($ref, 14, 1))->toBe('5', 'version 5')
        ->and(ReservationRef::forLine(7, '6f1c2e2a-3b4d-4c5e-8f90-a1b2c3d4e5f6'))->toBe($ref, 'stable')
        ->and(ReservationRef::forLine(7, '6F1C2E2A-3B4D-4C5E-8F90-A1B2C3D4E5F6'))->toBe($ref, 'the client UUID is read case-insensitively')
        ->and(ReservationRef::forLine(8, '6f1c2e2a-3b4d-4c5e-8f90-a1b2c3d4e5f6'))->toBe('0b860236-3ebe-50c4-b350-646bd5ca666e', 'another branch, another reservation');
})->group('unit', 'module:orders');

it('refuses a client UUID that is not a UUID', function () {
    ReservationRef::forLine(7, 'line-1');
})->throws(InvalidArgumentException::class)->group('unit', 'module:orders');
