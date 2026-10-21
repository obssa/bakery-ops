<?php

declare(strict_types=1);

use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Drivers\DriverRegistry;
use App\Modules\Notifications\Jobs\DeliverNotification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Shared\Models\Branch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationDrivers;
use Tests\Support\NotificationEvents;

pest()->group('module:notifications', 'feature');

/*
 * Step 2.5b (phase-2 plan section 5): after the inbox line is written, one DeliverNotification is queued per delivery that
 * the type's channels, the drivers that are on, the person's endpoints and the per-recipient choice allow. The send is queued
 * after commit, retries with the backoff of the listeners, and can never undo or block the inbox line: a failed external send
 * is recorded on the delivery row (attempts, last_error) and a driver that throws does not stop the others.
 */

beforeEach(function () {
    seedRoles();
    NotificationDrivers::allOff();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager', ['locale' => 'am']);
    $this->baker = makeUser($this->branch, 'cake_baker');
    withActor($this->admin->id);
    $this->notifier = fn (): Notifier => app(Notifier::class);
    $this->out = fn (int $alert = 21): array => NotificationEvents::stockOut($this->branch->id, 11, 4, $alert)->payload();
    $this->endpoint = fn ($user, string $driver = 'push', array $over = []): NotificationEndpoint => NotificationEndpoint::factory()
        ->{$driver}()->create($over + ['branch_id' => $this->branch->id, 'user_id' => $user->id]);
    $this->deliveries = fn () => NotificationDelivery::query()->orderBy('id')->get();
});

it('plans one delivery per endpoint on every channel the type allows and queues a job for each, after commit', function () {
    NotificationDrivers::array();
    Queue::fake();
    ($this->endpoint)($this->admin, 'push');
    ($this->endpoint)($this->admin, 'telegram');
    ($this->endpoint)($this->manager, 'push');

    $lines = ($this->notifier)()->send(new Collection([$this->admin, $this->manager, $this->baker]), 'stock_out', ($this->out)());

    $deliveries = ($this->deliveries)();
    expect($lines)->toHaveCount(3)
        ->and($deliveries)->toHaveCount(3)
        ->and($deliveries->pluck('driver')->sort()->values()->all())->toBe(['push', 'push', 'telegram'])
        ->and($deliveries->pluck('status')->unique()->all())->toBe(['pending'])
        ->and($deliveries->pluck('attempts')->unique()->all())->toBe([0])
        ->and($deliveries->every(fn (NotificationDelivery $d): bool => $d->branch_id === $this->branch->id))->toBeTrue();
    Queue::assertPushed(DeliverNotification::class, 3);
    foreach (Queue::pushed(DeliverNotification::class) as $job) {
        expect($job->afterCommit)->toBeTrue()->and($job->queue)->toBeNull();
    }
});

it('plans nothing for a channel the type does not use, for a driver that is off, or for a person with no endpoint', function () {
    Queue::fake();
    ($this->endpoint)($this->admin, 'push');
    ($this->endpoint)($this->admin, 'telegram');

    // every driver is off: the inbox line exists, no delivery row, no job
    ($this->notifier)()->send(new Collection([$this->admin, $this->manager]), 'stock_out', ($this->out)());
    expect(Notification::query()->count())->toBe(2)->and(($this->deliveries)())->toHaveCount(0);

    // line_ready is in_app only: even with the CI driver on and endpoints, nothing is planned
    NotificationDrivers::array();
    ($this->notifier)()->send(new Collection([$this->admin]), 'line_ready', NotificationEvents::lineReady($this->branch->id, $this->admin->id)->payload());
    expect(($this->deliveries)())->toHaveCount(0);

    // transfer_requested is in_app + push: the telegram endpoint is not used
    ($this->notifier)()->send(new Collection([$this->admin, $this->manager]), 'transfer_requested', NotificationEvents::transferRequested($this->branch->id, $this->baker->id)->payload());
    $deliveries = ($this->deliveries)();
    expect($deliveries)->toHaveCount(1)->and($deliveries->first()->driver)->toBe('push');
    Queue::assertPushed(DeliverNotification::class, 1);
});

it('keeps people chosen as in-app only on the inbox: a per-recipient choice the audience makes', function () {
    NotificationDrivers::array();
    Queue::fake();
    ($this->endpoint)($this->admin, 'push');
    ($this->endpoint)($this->baker, 'push');

    ($this->notifier)()->send(new Collection([$this->admin, $this->baker]), 'stock_out', ($this->out)(), null, [$this->baker->id]);

    $deliveries = ($this->deliveries)();
    expect(Notification::query()->count())->toBe(2, 'the inbox tells everyone')
        ->and($deliveries)->toHaveCount(1)
        ->and($deliveries->first()->notification->user_id)->toBe($this->admin->id);
});

it('does not plan to an endpoint that was revoked or deleted, nor to another person', function () {
    NotificationDrivers::array();
    Queue::fake();
    ($this->endpoint)($this->admin, 'push', ['revoked_at' => now()]);
    $gone = ($this->endpoint)($this->admin, 'push');
    $gone->delete();
    ($this->endpoint)($this->manager, 'push');
    $live = ($this->endpoint)($this->admin, 'push');

    ($this->notifier)()->send(new Collection([$this->admin]), 'stock_out', ($this->out)());

    expect(($this->deliveries)()->pluck('endpoint_id')->all())->toBe([$live->id]);
});

it('does not plan a second time for a redelivered event', function () {
    NotificationDrivers::array();
    Queue::fake();
    ($this->endpoint)($this->admin, 'push');

    ($this->notifier)()->send(new Collection([$this->admin]), 'transfer_requested', NotificationEvents::transferRequested($this->branch->id, $this->baker->id)->payload());
    ($this->notifier)()->send(new Collection([$this->admin]), 'transfer_requested', NotificationEvents::transferRequested($this->branch->id, $this->baker->id)->payload());

    expect(Notification::query()->count())->toBe(1)->and(($this->deliveries)())->toHaveCount(1);
    Queue::assertPushed(DeliverNotification::class, 1);
});

it('sends through the driver in the language of the recipient and marks the delivery sent', function () {
    $log = NotificationDrivers::array();
    ($this->endpoint)($this->admin, 'push');
    ($this->endpoint)($this->manager, 'telegram');

    ($this->notifier)()->send(new Collection([$this->admin, $this->manager]), 'stock_out', ($this->out)());

    $sent = $log->sent();
    expect($sent)->toHaveCount(2);
    $byUser = collect($sent)->keyBy('user_id');
    expect($byUser[$this->admin->id]['locale'])->toBe('en')
        ->and($byUser[$this->admin->id]['channel'])->toBe('push')
        ->and($byUser[$this->admin->id]['title'])->toBe('Black forest cake is out of stock')
        ->and($byUser[$this->manager->id]['locale'])->toBe('am')
        ->and($byUser[$this->manager->id]['channel'])->toBe('telegram')
        ->and($byUser[$this->manager->id]['title'])->toBe('ብላክ ፎረስት ኬክ አልቋል')
        ->and($byUser[$this->manager->id]['body'])->toBe('በየማሳያ ሣጥን ምንም አልቀረም።');
    foreach (($this->deliveries)() as $delivery) {
        expect($delivery->status)->toBe('sent')->and($delivery->attempts)->toBe(1)->and($delivery->sent_at)->not->toBeNull()->and($delivery->last_error)->toBeNull();
    }
    expect(NotificationEndpoint::query()->whereNotNull('last_success_at')->count())->toBe(2);
});

it('never sends a delivery twice', function () {
    $log = NotificationDrivers::array();
    ($this->endpoint)($this->admin, 'push');
    ($this->notifier)()->send(new Collection([$this->admin]), 'stock_out', ($this->out)());
    $delivery = ($this->deliveries)()->first();

    DeliverNotification::dispatchSync($delivery->id, $this->branch->id);
    DeliverNotification::dispatchSync($delivery->id, $this->branch->id);

    expect($log->sent())->toHaveCount(1)->and($delivery->fresh()->attempts)->toBe(1);
});

it('keeps the inbox line when the send fails, records the error and the attempts, and fails the delivery after the third try', function () {
    $log = NotificationDrivers::array();
    $endpoint = ($this->endpoint)($this->admin, 'push');
    $log->failAll('push service unavailable');

    $lines = ($this->notifier)()->send(new Collection([$this->admin]), 'stock_out', ($this->out)());

    $delivery = ($this->deliveries)()->first();
    expect($lines)->toHaveCount(1, 'a failed external send never blocks or rolls back the inbox line')
        ->and(Notification::query()->count())->toBe(1)
        ->and($delivery->status)->toBe('pending', 'still being retried')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_error)->toBe('push service unavailable');

    DeliverNotification::dispatchSync($delivery->id, $this->branch->id);
    expect($delivery->fresh()->status)->toBe('pending')->and($delivery->fresh()->attempts)->toBe(2);
    DeliverNotification::dispatchSync($delivery->id, $this->branch->id);

    $delivery = $delivery->fresh();
    expect($delivery->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(3)
        ->and($delivery->last_error)->toBe('push service unavailable')
        ->and($delivery->sent_at)->toBeNull()
        ->and(Notification::query()->count())->toBe(1)
        ->and($endpoint->fresh()->revoked_at)->toBeNull('a failure that is not "gone" keeps the endpoint');

    DeliverNotification::dispatchSync($delivery->id, $this->branch->id);
    expect($delivery->fresh()->attempts)->toBe(3, 'a failed delivery is not tried again');
});

it('stores at most 255 characters of the error', function () {
    $log = NotificationDrivers::array();
    ($this->endpoint)($this->admin, 'push');
    $log->failAll(str_repeat('x', 600));

    ($this->notifier)()->send(new Collection([$this->admin]), 'stock_out', ($this->out)());

    expect(mb_strlen((string) ($this->deliveries)()->first()->last_error))->toBeLessThanOrEqual(255);
});

it('marks a delivery gone and revokes the endpoint when the push service says the subscription no longer exists', function () {
    $log = NotificationDrivers::array();
    $endpoint = ($this->endpoint)($this->admin, 'push');
    $other = ($this->endpoint)($this->manager, 'push');
    $log->gone($endpoint->id);

    ($this->notifier)()->send(new Collection([$this->admin, $this->manager]), 'stock_out', ($this->out)());

    $byEndpoint = ($this->deliveries)()->keyBy('endpoint_id');
    expect($byEndpoint[$endpoint->id]->status)->toBe('gone')
        ->and($byEndpoint[$endpoint->id]->attempts)->toBe(1)
        ->and($endpoint->fresh()->revoked_at)->not->toBeNull()
        ->and($byEndpoint[$other->id]->status)->toBe('sent')
        ->and($other->fresh()->revoked_at)->toBeNull();
});

it('lets one failing driver leave the others to deliver', function () {
    $log = NotificationDrivers::array();
    $push = ($this->endpoint)($this->admin, 'push');
    $telegram = ($this->endpoint)($this->admin, 'telegram');
    $log->fail($push->id, 'boom');

    ($this->notifier)()->send(new Collection([$this->admin]), 'stock_out', ($this->out)());

    $byEndpoint = ($this->deliveries)()->keyBy('endpoint_id');
    expect($byEndpoint[$push->id]->status)->toBe('pending')
        ->and($byEndpoint[$push->id]->last_error)->toBe('boom')
        ->and($byEndpoint[$telegram->id]->status)->toBe('sent')
        ->and(Notification::query()->count())->toBe(1);
});

it('records a delivery as failed, without retrying, when its driver was switched off after it was planned', function () {
    NotificationDrivers::array();
    Queue::fake();
    ($this->endpoint)($this->admin, 'push');
    ($this->notifier)()->send(new Collection([$this->admin]), 'stock_out', ($this->out)());
    $delivery = ($this->deliveries)()->first();

    NotificationDrivers::allOff();
    DeliverNotification::dispatchSync($delivery->id, $this->branch->id);

    $delivery = $delivery->fresh();
    expect($delivery->status)->toBe('failed')
        ->and($delivery->last_error)->toBe('driver_disabled')
        ->and($delivery->attempts)->toBe(0)
        ->and(app(DriverRegistry::class)->loaded())->not->toContain('push');
});

it('does not send to a person who is no longer active, and records why', function () {
    $log = NotificationDrivers::array();
    Queue::fake();
    ($this->endpoint)($this->manager, 'push');
    ($this->notifier)()->send(new Collection([$this->manager]), 'stock_out', ($this->out)());
    $delivery = ($this->deliveries)()->first();
    $this->manager->forceFill(['is_active' => false])->save();

    DeliverNotification::dispatchSync($delivery->id, $this->branch->id);

    expect($log->sent())->toBe([])
        ->and($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->last_error)->toBe('recipient_inactive');
});

it('is a queued job on the default queue with three tries, the listeners backoff, and a failed() that marks the delivery', function () {
    $job = new DeliverNotification(5, 1);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 120])
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->queue)->toBeNull();
});

it('marks the delivery failed when the queue gives the job up for another reason', function () {
    NotificationDrivers::array();
    Queue::fake();
    ($this->endpoint)($this->admin, 'push');
    ($this->notifier)()->send(new Collection([$this->admin]), 'stock_out', ($this->out)());
    $delivery = ($this->deliveries)()->first();

    (new DeliverNotification($delivery->id, $this->branch->id))->failed(new RuntimeException('worker gave up'));

    expect($delivery->fresh()->status)->toBe('failed')->and($delivery->fresh()->last_error)->toBe('worker gave up');
});
