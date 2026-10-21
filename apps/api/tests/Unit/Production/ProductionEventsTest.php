<?php

declare(strict_types=1);

use App\Shared\Events\DomainEvent;
use App\Shared\Events\ProductionRequested;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Tests\Support\NotificationEvents;

pest()->group('unit', 'module:production');

/*
 * The two events of production requests (step 2.7b; phase-2 plan section 5), in App\Shared\Events so Notifications subscribes
 * without importing Production: array payloads of ids, decimal strings and bilingual names, never a model, dispatched after
 * the writing transaction commits. Notifications writes its text from the payload alone, so the keys here are its contract.
 */

it('announces a request with the product and the station named in both languages, after commit', function () {
    $event = new ProductionRequested(
        3, 51, 17, 'Black forest cake', 'ብላክ ፎረስት ኬክ', '12.0000', 'pcs', 4, 'Cake room', 'የኬክ ክፍል', 'stock_out', 'high',
        '2026-10-25T05:00:00+00:00', 9, CarbonImmutable::parse('2026-10-24 08:00:00', 'UTC'),
    );

    expect($event)->toBeInstanceOf(DomainEvent::class)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->branchId)->toBe(3)
        ->and($event->name())->toBe('production_requested')
        ->and($event->payload())->toBe([
            'request_id' => 51,
            'product_item_id' => 17,
            'product_name_en' => 'Black forest cake',
            'product_name_am' => 'ብላክ ፎረስት ኬክ',
            'qty' => '12.0000',
            'unit' => 'pcs',
            'station_id' => 4,
            'station_name_en' => 'Cake room',
            'station_name_am' => 'የኬክ ክፍል',
            'reason' => 'stock_out',
            'priority' => 'high',
            'due_at' => '2026-10-25T05:00:00+00:00',
            'requested_by_user_id' => 9,
        ])
        ->and($event->toArray()['occurred_at'])->toBe('2026-10-24T08:00:00Z');
});

it('announces an assignment with the baker, the planner and the version the assignment made', function () {
    $event = NotificationEvents::productionAssigned(3, 6, 5, 51, ['version' => 4]);

    expect($event)->toBeInstanceOf(DomainEvent::class)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->name())->toBe('production_assigned')
        ->and($event->payload())->toBe([
            'request_id' => 51,
            'product_item_id' => 17,
            'product_name_en' => 'Black forest cake',
            'product_name_am' => 'ብላክ ፎረስት ኬክ',
            'qty' => '12.0000',
            'unit' => 'pcs',
            'station_id' => 1,
            'station_name_en' => 'Cake room',
            'station_name_am' => 'የኬክ ክፍል',
            'assigned_to_user_id' => 6,
            'assigned_by_user_id' => 5,
            'version' => 4,
        ]);
});

it('carries only ids, strings and numbers: no model crosses a module boundary', function () {
    $events = [NotificationEvents::productionRequested(1, 5), NotificationEvents::productionAssigned(1, 6, 5)];
    foreach ($events as $event) {
        foreach ($event->payload() as $key => $value) {
            expect(is_scalar($value) || $value === null)->toBeTrue("{$event->name()}.{$key} is a plain value");
        }
    }
});
