<?php

declare(strict_types=1);

use App\Shared\Actor\ActorContext;
use App\Shared\Actor\ActorRequired;
use App\Shared\Events\StockLow;
use App\Shared\Events\StockOut;
use App\Shared\Events\StockRecovered;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/*
 * Phase 2, step 2.1 (F4, ADR 0011): queued work has no request, so it borrows the stamp of the movement that caused it.
 * ActorContext::runAs() puts a stamp in place for the length of a callback and puts back exactly what was there, so a
 * worker that runs many jobs in one process never carries one person into the next job. The stock alert events are the
 * cross-module payloads Production and Notifications will subscribe to (App\Shared\Events, array payloads only).
 */

it('runs a callback as the given stamp and answers its result', function () {
    $actor = app(ActorContext::class);
    $stamp = ['user_id' => 5, 'acting_user_id' => 9, 'shift_instance_id' => 4, 'station_id' => 2, 'device_id' => 3];

    $seen = $actor->runAs($stamp, fn (): array => $actor->toArray());

    expect($seen)->toBe($stamp);
})->group('module:shared', 'unit');

it('restores exactly the previous state afterwards: anonymous stays anonymous, a logged-in user stays logged in', function () {
    $actor = app(ActorContext::class);
    $stamp = ['user_id' => 5, 'acting_user_id' => 9, 'shift_instance_id' => null, 'station_id' => 2, 'device_id' => null];

    $actor->runAs($stamp, fn () => null);
    expect($actor->isAuthenticated())->toBeFalse()
        ->and($actor->toArray())->toBe(['user_id' => null, 'acting_user_id' => null, 'shift_instance_id' => null, 'station_id' => null, 'device_id' => null]);

    $actor->authenticate(1)->actingAs(null)->atStation(7)->onDevice(8)->inShift(6);
    $before = $actor->toArray();
    $actor->runAs($stamp, fn () => null);

    expect($actor->toArray())->toBe($before);
})->group('module:shared', 'unit');

it('restores the previous state when the callback throws, and rethrows', function () {
    $actor = app(ActorContext::class)->authenticate(1)->atStation(7);
    $before = $actor->toArray();

    expect(fn () => $actor->runAs(['user_id' => 5, 'acting_user_id' => null, 'shift_instance_id' => null, 'station_id' => null, 'device_id' => null], function (): never {
        throw new RuntimeException('the job failed');
    }))->toThrow(RuntimeException::class, 'the job failed');

    expect($actor->toArray())->toBe($before);
})->group('module:shared', 'unit');

it('restores the state after nested runs, innermost first', function () {
    $actor = app(ActorContext::class);
    $stamp = fn (int $user): array => ['user_id' => $user, 'acting_user_id' => null, 'shift_instance_id' => null, 'station_id' => null, 'device_id' => null];
    $trail = [];

    $actor->runAs($stamp(1), function () use ($actor, $stamp, &$trail): void {
        $trail[] = $actor->userId();
        $actor->runAs($stamp(2), function () use ($actor, &$trail): void {
            $trail[] = $actor->userId();
        });
        $trail[] = $actor->userId();
    });
    $trail[] = $actor->userId();

    expect($trail)->toBe([1, 2, 1, null]);
})->group('module:shared', 'unit');

it('carries a stamp that lets a write through, which an anonymous worker could not make', function () {
    $actor = app(ActorContext::class);

    expect(fn () => $actor->stamp())->toThrow(ActorRequired::class);

    $stamped = $actor->runAs(
        ['user_id' => 5, 'acting_user_id' => 9, 'shift_instance_id' => null, 'station_id' => 2, 'device_id' => null],
        fn (): array => $actor->stamp(),
    );

    expect($stamped)->toBe(['created_by_user_id' => 5, 'acting_user_id' => 9, 'shift_instance_id' => null, 'station_id' => 2, 'device_id' => null]);
})->group('module:shared', 'unit');

it('describes a stock-out in ids, decimal strings and both languages, and is dispatched after commit', function () {
    $at = CarbonImmutable::parse('2026-10-21 07:30:00', 'UTC');
    $event = new StockOut(
        branchId: 3, itemId: 11, locationId: 4,
        itemNameEn: 'Black forest cake', itemNameAm: 'ብላክ ፎረስት', locationNameEn: 'Display case', locationNameAm: 'የማሳያ ሣጥን',
        itemKind: 'product', fulfilment: 'to_stock', available: '0.0000', onHand: '5.0000', reserved: '5.0000',
        alertId: 21, causedByUserId: 8, stationId: 2, productionStationId: 5, occurredAt: $at,
    );

    expect($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->name())->toBe('stock_out')
        ->and($event->branchId)->toBe(3)
        ->and($event->payload())->toBe([
            'item_id' => 11, 'location_id' => 4,
            'item_name_en' => 'Black forest cake', 'item_name_am' => 'ብላክ ፎረስት',
            'location_name_en' => 'Display case', 'location_name_am' => 'የማሳያ ሣጥን',
            'item_kind' => 'product', 'fulfilment' => 'to_stock',
            'available' => '0.0000', 'on_hand' => '5.0000', 'reserved' => '5.0000',
            'alert_id' => 21, 'caused_by_user_id' => 8, 'station_id' => 2, 'production_station_id' => 5,
        ])
        ->and($event->toArray()['event'])->toBe('stock_out')
        ->and($event->toArray()['branch_id'])->toBe(3)
        ->and($event->toArray()['occurred_at'])->toBe('2026-10-21T07:30:00Z');
})->group('module:shared', 'unit');

it('describes a recovery and a low-stock alert, the latter with the rule it crossed', function () {
    $recovered = new StockRecovered(branchId: 3, itemId: 11, locationId: 4, type: 'out', alertId: 21);
    expect($recovered)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($recovered->name())->toBe('stock_recovered')
        ->and($recovered->payload())->toBe(['item_id' => 11, 'location_id' => 4, 'type' => 'out', 'alert_id' => 21]);

    $low = new StockLow(
        branchId: 3, itemId: 12, locationId: 1,
        itemNameEn: 'Flour', itemNameAm: 'ዱቄት', locationNameEn: 'Store', locationNameAm: 'መጋዘን',
        itemKind: 'ingredient', fulfilment: 'bought_in', available: '4000.0000', onHand: '4000.0000', reserved: '0.0000',
        alertId: 22, causedByUserId: null, stationId: null, productionStationId: null, minQty: '5000.0000', reorderQty: '25000.0000', notifyRoles: ['admin', 'manager'],
    );
    expect($low)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($low->name())->toBe('stock_low')
        ->and($low->payload())->toMatchArray([
            'item_id' => 12, 'location_id' => 1, 'item_kind' => 'ingredient', 'available' => '4000.0000',
            'alert_id' => 22, 'caused_by_user_id' => null,
            'min_qty' => '5000.0000', 'reorder_qty' => '25000.0000', 'notify_roles' => ['admin', 'manager'],
        ]);
})->group('module:shared', 'unit');
