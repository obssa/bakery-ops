<?php

declare(strict_types=1);

use App\Shared\Events\BatchDone;
use App\Shared\Events\DomainEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Tests\Support\NotificationEvents;

pest()->group('unit', 'module:production');

/*
 * BatchDone (step 2.7c; phase-2 plan section 5): in App\Shared\Events so Notifications subscribes without importing Production.
 * An array payload of ids, decimal strings and bilingual names, never a model, dispatched after the writing transaction commits.
 * `status` says which of two things happened: the baker finished and an overseer must sign off (pending_confirmation), or the
 * batch is confirmed. The person who asked and the baker travel with it, because Notifications may not read Production's tables
 * to find who to tell; send_back_count keys the "please sign off" line so a batch finished again after a send back is heard again.
 */

it('announces a batch with the product, the numbers and the people in a plain payload, after commit', function () {
    $event = NotificationEvents::batchDone(3, 'confirmed', 6, 7, 5, 6, 71, ['produced' => '10.0000', 'waste' => '2.0000', 'sendBackCount' => 1]);

    expect($event)->toBeInstanceOf(DomainEvent::class)->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->branchId)->toBe(3)
        ->and($event->name())->toBe('batch_done')
        ->and($event->payload())->toBe([
            'batch_id' => 71,
            'request_id' => 51,
            'status' => 'confirmed',
            'product_item_id' => 17,
            'product_name_en' => 'Black forest cake',
            'product_name_am' => 'ብላክ ፎረስት ኬክ',
            'produced_qty' => '10.0000',
            'waste_qty' => '2.0000',
            'unit' => 'pcs',
            'station_id' => 1,
            'station_name_en' => 'Cake room',
            'station_name_am' => 'የኬክ ክፍል',
            'finished_by_user_id' => 6,
            'confirmed_by_user_id' => 7,
            'requested_by_user_id' => 5,
            'assigned_to_user_id' => 6,
            'send_back_count' => 1,
        ]);
});

it('says pending_confirmation with nobody confirming yet, and carries only plain values', function () {
    $event = NotificationEvents::batchDone(3, 'pending_confirmation', 6, null, 5, null);

    expect($event->payload()['status'])->toBe('pending_confirmation')
        ->and($event->payload()['confirmed_by_user_id'])->toBeNull()
        ->and($event->payload()['assigned_to_user_id'])->toBeNull();
    foreach ($event->payload() as $key => $value) {
        expect(is_scalar($value) || $value === null)->toBeTrue("batch_done.{$key} is a plain value");
    }
});

it('refuses a status other than the two it knows', function () {
    expect(fn () => NotificationEvents::batchDone(3, 'started', 6, null, 5, 6))->toThrow(InvalidArgumentException::class);
});
