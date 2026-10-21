<?php

declare(strict_types=1);

use App\Modules\Production\Support\AutoRequestUuid;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

pest()->group('unit', 'module:production');

/*
 * The client_uuid of an automatic production request (phase-2 plan section 5): the UUIDv5 of "stock-out-alert:{branch}:{alert}",
 * so a redelivered or racing job can only ever reach the same request, and two branches that share an alert number never share
 * one. Pinned here: a change would let a replay make a second request.
 */

it('is the UUIDv5 of the branch and the alert, lower case, the same every time', function () {
    $uuid = AutoRequestUuid::forAlert(3, 21);

    expect(Str::isUuid($uuid))->toBeTrue()
        ->and($uuid)->toBe(strtolower($uuid))
        ->and($uuid)->toBe(Uuid::uuid5(Uuid::NAMESPACE_URL, 'stock-out-alert:3:21')->toString())
        ->and(Uuid::fromString($uuid)->getVersion())->toBe(5)
        ->and(AutoRequestUuid::forAlert(3, 21))->toBe($uuid);
});

it('differs by alert and by branch', function () {
    expect(AutoRequestUuid::forAlert(3, 21))->not->toBe(AutoRequestUuid::forAlert(3, 22))
        ->and(AutoRequestUuid::forAlert(3, 21))->not->toBe(AutoRequestUuid::forAlert(4, 21));
});
