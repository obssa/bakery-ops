<?php

declare(strict_types=1);

use App\Modules\Notifications\Listeners\NotifyPaymentDisputed;
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
 * Step 4.3c (phase-4 plan section 5): PaymentDisputed tells the people who verify payments (payments.verify: the admin and the managers) that a
 * payment was disputed, in the inbox, by push and by Telegram where those are switched on, severity warning, one line per payment however often
 * the event is delivered (inbox key payment_disputed:{payment}), never the person who disputed it. The line says the order number, the amount,
 * the channel in the reader's language and the reason, and opens the payment on the admin's payments screen. Queued after commit on the default
 * queue, run as the person who disputed it, in the event's branch.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager');
    $this->otherManager = makeUser($this->branch, 'manager');
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->chef = makeUser($this->branch, 'chef');
    $this->gone = makeUser($this->branch, 'manager', ['is_active' => false]);
    withActor($this->admin->id);
    $this->heard = fn () => Notification::query()->where('type', 'payment_disputed')->orderBy('user_id')->pluck('user_id')->all();
    $this->ids = fn (...$users) => collect($users)->map(fn ($u): int => $u->id)->sort()->values()->all();
});

it('is queued after commit on the default queue and registered for its event', function () {
    Queue::fake();

    event(NotificationEvents::paymentDisputed($this->branch->id, $this->manager->id));

    $pushed = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === NotifyPaymentDisputed::class);
    expect($pushed)->toHaveCount(1)
        ->and($pushed->first()->queue)->toBeNull()
        ->and(is_subclass_of(NotifyPaymentDisputed::class, ShouldQueue::class))->toBeTrue()
        ->and(is_subclass_of(NotifyPaymentDisputed::class, ShouldHandleEventsAfterCommit::class))->toBeTrue()
        ->and(Notification::query()->count())->toBe(0);
});

it('tells the holders of payments.verify but not the person who disputed it, an inactive manager or anyone else', function () {
    app(NotifyPaymentDisputed::class)->handle(NotificationEvents::paymentDisputed($this->branch->id, $this->manager->id));

    expect(($this->heard)())->toBe(($this->ids)($this->admin, $this->otherManager));

    Notification::query()->delete();
    app(NotifyPaymentDisputed::class)->handle(NotificationEvents::paymentDisputed($this->branch->id, $this->admin->id, 72));
    expect(($this->heard)())->toBe(($this->ids)($this->manager, $this->otherManager));
});

it('writes one warning line per payment: a redelivery adds nothing, another payment is a new line', function () {
    $event = NotificationEvents::paymentDisputed($this->branch->id, $this->manager->id, 71);
    app(NotifyPaymentDisputed::class)->handle($event);
    app(NotifyPaymentDisputed::class)->handle($event);
    expect(Notification::query()->count())->toBe(2);

    app(NotifyPaymentDisputed::class)->handle(NotificationEvents::paymentDisputed($this->branch->id, $this->manager->id, 72));

    $line = Notification::query()->where('user_id', $this->admin->id)->orderBy('id')->first();
    expect(Notification::query()->count())->toBe(4)
        ->and($line->severity)->toBe('warning')
        ->and($line->dedupe_key)->toBe('payment_disputed:71')
        ->and($line->deep_link)->toBe('/admin/payments?payment=71')
        ->and($line->payload['caused_by_user_id'] ?? null)->toBe($this->manager->id);
});

it('says the order, the amount, the channel and the reason, each side in its own language', function () {
    $rendered = app(NotificationText::class)->render('payment_disputed', NotificationEvents::paymentDisputed($this->branch->id, $this->manager->id)->payload());

    expect($rendered->title->en)->toContain('#17')
        ->and($rendered->body->en)->toContain('25.00')->toContain('Telebirr')->toContain('Not on the bank statement')
        ->and($rendered->title->am)->toContain('#17')
        ->and($rendered->body->am)->toContain('25.00')->toContain('ቴሌብር')->toContain('Not on the bank statement')
        ->and($rendered->body->am)->not->toContain('Telebirr')
        ->and($rendered->title->am)->not->toBe($rendered->title->en);
});

it('is in the registry with in-app, push and Telegram, and keyed per payment', function () {
    $payload = NotificationEvents::paymentDisputed(1, 5, 73)->payload();

    expect(NotificationTypes::has('payment_disputed'))->toBeTrue()
        ->and(NotificationTypes::channels('payment_disputed'))->toBe(['in_app', 'push', 'telegram'])
        ->and(NotificationTypes::severity('payment_disputed', $payload))->toBe('warning')
        ->and(NotificationTypes::inboxKey('payment_disputed', $payload))->toBe('payment_disputed:73')
        ->and(NotificationTypes::gateKey('payment_disputed', $payload))->toBeNull()
        ->and(NotificationTypes::deepLink('payment_disputed', $payload))->toBe('/admin/payments?payment=73');
});
