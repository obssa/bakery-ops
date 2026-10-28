<?php

declare(strict_types=1);

use App\Modules\Notifications\Listeners\NotifyRefund;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationText;
use App\Modules\Notifications\Support\NotificationTypes;
use App\Shared\Models\Branch;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationEvents;

pest()->group('module:notifications', 'feature');

/*
 * Step 4.3d (phase-4 plan section 5, NotifyRefund): a refund paid back (RefundPaid, after the commit) tells the people who check the money
 * (payments.verify: the admin and the managers) in the inbox only, severity info, one line per refund however often the event is delivered
 * (order_refunded:{refund}), never the cashier who asked or the manager whose PIN approved it (they were there). The line says the order number,
 * the amount, who approved it and why, and opens the order's record (/admin/orders/record?id=). Written from the payload alone: Notifications
 * reads neither Orders nor Payments. Queued after commit on the default queue, run as the person who asked, in the event's branch.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager');
    $this->otherManager = makeUser($this->branch, 'manager');
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->chef = makeUser($this->branch, 'chef');
    makeUser($this->branch, 'manager', ['is_active' => false]);
    withActor($this->admin->id);
    $this->heard = fn () => Notification::query()->where('type', 'order_refunded')->orderBy('user_id')->pluck('user_id')->all();
    $this->ids = fn (...$users) => collect($users)->map(fn ($u): int => $u->id)->sort()->values()->all();
});

it('is queued after commit on the default queue and registered for RefundPaid', function () {
    Queue::fake();

    event(NotificationEvents::refundPaid($this->branch->id, $this->cashier->id, $this->manager->id));

    $pushed = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === NotifyRefund::class);
    expect($pushed)->toHaveCount(1)
        ->and($pushed->first()->queue)->toBeNull()
        ->and(is_subclass_of(NotifyRefund::class, ShouldQueue::class))->toBeTrue()
        ->and(is_subclass_of(NotifyRefund::class, ShouldHandleEventsAfterCommit::class))->toBeTrue()
        ->and(Notification::query()->count())->toBe(0);
});

it('tells the holders of payments.verify but not the cashier who asked, the manager who approved, an inactive manager or anyone else', function () {
    app(NotifyRefund::class)->handle(NotificationEvents::refundPaid($this->branch->id, $this->cashier->id, $this->manager->id));

    expect(($this->heard)())->toBe(($this->ids)($this->admin, $this->otherManager));

    Notification::query()->delete();
    app(NotifyRefund::class)->handle(NotificationEvents::refundPaid($this->branch->id, $this->manager->id, $this->admin->id, ['refundId' => 5]));
    expect(($this->heard)())->toBe(($this->ids)($this->otherManager));
});

it('writes one info line per refund in the inbox only: a redelivery adds nothing, another refund is a new line', function () {
    $event = NotificationEvents::refundPaid($this->branch->id, $this->cashier->id, $this->manager->id);
    app(NotifyRefund::class)->handle($event);
    app(NotifyRefund::class)->handle($event);
    expect(Notification::query()->count())->toBe(2);

    app(NotifyRefund::class)->handle(NotificationEvents::refundPaid($this->branch->id, $this->cashier->id, $this->manager->id, ['refundId' => 6]));

    $line = Notification::query()->where('user_id', $this->admin->id)->orderBy('id')->first();
    expect(Notification::query()->count())->toBe(4)
        ->and($line->severity)->toBe('info')
        ->and($line->dedupe_key)->toBe('order_refunded:4')
        ->and($line->deep_link)->toBe('/admin/orders/record?id=21')
        ->and($line->payload['caused_by_user_id'] ?? null)->toBe($this->cashier->id);
});

it('says the order, the amount, who approved it and why, each side in its own language', function () {
    $rendered = app(NotificationText::class)->render('order_refunded', NotificationEvents::refundPaid($this->branch->id, $this->cashier->id, $this->manager->id)->payload());

    expect($rendered->title->en)->toContain('#17')
        ->and($rendered->body->en)->toContain('120.00')->toContain('Meron')->toContain('The cake was the wrong one')
        ->and($rendered->title->am)->toContain('#17')
        ->and($rendered->body->am)->toContain('120.00')->toContain('Meron')->toContain('The cake was the wrong one')
        ->and($rendered->title->am)->not->toBe($rendered->title->en);
});

it('is in the registry with the inbox only, keyed per refund, and opens the order\'s record', function () {
    $payload = NotificationEvents::refundPaid(1, 5, 6, ['refundId' => 73, 'orderId' => 40])->payload();

    expect(NotificationTypes::has('order_refunded'))->toBeTrue()
        ->and(NotificationTypes::channels('order_refunded'))->toBe(['in_app'])
        ->and(NotificationTypes::severity('order_refunded', $payload))->toBe('info')
        ->and(NotificationTypes::inboxKey('order_refunded', $payload))->toBe('order_refunded:73')
        ->and(NotificationTypes::gateKey('order_refunded', $payload))->toBeNull()
        ->and(NotificationTypes::deepLink('order_refunded', $payload))->toBe('/admin/orders/record?id=40');
});
