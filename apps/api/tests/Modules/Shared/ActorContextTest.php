<?php

declare(strict_types=1);

use App\Shared\Actor\ActorContext;
use App\Shared\Actor\ActorRequired;

/*
 * Rule 4: every write records who (user, plus the short-code actor if different), which shift, station, device.
 * ActorContext is the request-scoped carrier of that stamp; Identity fills it, Audit and Inventory read it.
 */

it('starts anonymous and refuses to stamp a write without a user', function () {
    $actor = app(ActorContext::class);

    expect($actor->isAuthenticated())->toBeFalse()
        ->and($actor->userId())->toBeNull()
        ->and($actor->effectiveUserId())->toBeNull()
        ->and(fn () => $actor->requireUserId())->toThrow(ActorRequired::class)
        ->and(fn () => $actor->stamp())->toThrow(ActorRequired::class);
})->group('module:shared', 'unit');

it('carries user, acting-as, shift, station and device into one stamp', function () {
    $actor = app(ActorContext::class)->authenticate(5)->actingAs(9)->atStation(2)->onDevice(3)->inShift(4);

    expect($actor->isAuthenticated())->toBeTrue()
        ->and($actor->userId())->toBe(5)
        ->and($actor->actingUserId())->toBe(9)
        ->and($actor->effectiveUserId())->toBe(9)
        ->and($actor->stationId())->toBe(2)
        ->and($actor->deviceId())->toBe(3)
        ->and($actor->shiftInstanceId())->toBe(4)
        ->and($actor->stamp())->toBe([
            'created_by_user_id' => 5,
            'acting_user_id' => 9,
            'shift_instance_id' => 4,
            'station_id' => 2,
            'device_id' => 3,
        ]);
})->group('module:shared', 'unit');

it('stamps nulls for what is not known yet, as Phase 0 and 1 rows legitimately will', function () {
    $actor = app(ActorContext::class)->authenticate(5);

    expect($actor->effectiveUserId())->toBe(5)
        ->and($actor->stamp())->toBe([
            'created_by_user_id' => 5,
            'acting_user_id' => null,
            'shift_instance_id' => null,
            'station_id' => null,
            'device_id' => null,
        ]);
})->group('module:shared', 'unit');

it('is one instance for the whole request and can be reset', function () {
    $actor = app(ActorContext::class)->authenticate(5);

    expect(app(ActorContext::class))->toBe($actor)
        ->and(app(ActorContext::class)->userId())->toBe(5);

    $actor->reset();
    expect($actor->isAuthenticated())->toBeFalse();
})->group('module:shared', 'unit');
