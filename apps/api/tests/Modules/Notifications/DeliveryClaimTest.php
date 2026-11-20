<?php

declare(strict_types=1);

use App\Modules\Notifications\Drivers\PushDriver;
use App\Modules\Notifications\Jobs\DeliverNotification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Modules\Notifications\Support\RecipientLocale;
use App\Shared\Models\Branch;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Tests\Support\NotificationDrivers;

pest()->group('module:notifications', 'feature', 'concurrency');

/*
 * Item 13 of the 2026-11-20 state-machine review (ADR 0031): a delivery is claimed before it is sent. The check that it is still pending
 * and the send run while one copy of DeliverNotification holds the delivery's lock, and the row is read again once the lock is held, so
 * two copies of the job (a redelivery after the queue's retry_after, a second worker) never both see "pending" and both send. The copy
 * that finds the lock taken does nothing: the holder finishes the delivery or is itself redelivered once the lock has expired.
 */

beforeEach(function () {
    seedRoles();
    NotificationDrivers::allOff();
    NotificationDrivers::push();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);

    $this->requests = [];
    /** the push service's answers; each item is a Response or a callable run when the request is made */
    $this->respond = function (mixed ...$answers): void {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->requests));
        app()->bind('notifications.push.client', fn ($app, array $parameters): WebPush => new WebPush(
            ['VAPID' => $parameters['vapid']], [], new Client(['handler' => $stack, 'http_errors' => false]),
        ));
    };
    $browser = VAPID::createVapidKeys();
    $this->endpoint = NotificationEndpoint::factory()->push()->create([
        'branch_id' => $this->branch->id, 'user_id' => $this->admin->id,
        'address' => json_encode([
            'endpoint' => 'https://push.example.test/send/claim',
            'keys' => ['p256dh' => $browser['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')],
        ], JSON_THROW_ON_ERROR),
    ]);
    /** one delivery planned by hand, so that nothing has run it yet */
    $this->planned = function (): NotificationDelivery {
        $line = Notification::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id]);

        return NotificationDelivery::factory()->create([
            'branch_id' => $this->branch->id, 'notification_id' => $line->id, 'endpoint_id' => $this->endpoint->id, 'driver' => 'push',
        ]);
    };
});

it('sends once when a second copy of the job runs while the first is still sending', function () {
    $delivery = null;
    $copies = 0;
    ($this->respond)(
        function () use (&$delivery, &$copies): Response {
            // the push service is slow; meanwhile another worker picks up a second copy of the same job
            $copies++;
            DeliverNotification::dispatchSync($delivery->id, $this->branch->id);

            return new Response(201);
        },
        new Response(201),
    );
    $delivery = ($this->planned)();

    DeliverNotification::dispatchSync($delivery->id, $this->branch->id);

    $delivery = $delivery->fresh();
    expect($copies)->toBe(1)
        ->and($this->requests)->toHaveCount(1, 'the second copy found the delivery claimed and sent nothing')
        ->and($delivery->status)->toBe('sent')
        ->and($delivery->attempts)->toBe(1);
});

it('leaves a delivery alone while another worker holds it, also when the queue gives that copy up, and sends it once it is free', function () {
    ($this->respond)(new Response(201));
    $delivery = ($this->planned)();
    $other = Cache::lock(DeliverNotification::lockName($delivery->id), DeliverNotification::CLAIM_SECONDS);
    expect($other->get())->toBeTrue();

    DeliverNotification::dispatchSync($delivery->id, $this->branch->id);
    (new DeliverNotification($delivery->id, $this->branch->id))->failed(new RuntimeException('worker gave up'));

    expect($this->requests)->toHaveCount(0)
        ->and($delivery->fresh()->status)->toBe('pending', 'the copy holding it decides how it ends')
        ->and($delivery->fresh()->attempts)->toBe(0);

    $other->release();
    DeliverNotification::dispatchSync($delivery->id, $this->branch->id);

    expect($this->requests)->toHaveCount(1)
        ->and($delivery->fresh()->status)->toBe('sent');
});

it('reads the delivery again once it holds it: a copy that waited behind one that sent it sends nothing', function () {
    ($this->respond)(new Response(201), new Response(201));
    $delivery = ($this->planned)();
    $job = new DeliverNotification($delivery->id, $this->branch->id);

    DeliverNotification::dispatchSync($delivery->id, $this->branch->id);
    dispatch_sync($job);

    expect($this->requests)->toHaveCount(1)->and($delivery->fresh()->attempts)->toBe(1);
});

it('holds a delivery for less time than the queue waits before it hands a job out again, so a worker that died never strands one', function () {
    expect(DeliverNotification::CLAIM_SECONDS)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and(DeliverNotification::CLAIM_SECONDS)->toBeGreaterThan(PushDriver::TIMEOUT_SECONDS, 'a push send ends inside the claim')
        ->and(PushDriver::CONNECT_TIMEOUT_SECONDS)->toBeLessThan(PushDriver::TIMEOUT_SECONDS);
});

it('builds the real push client with its timeouts when no test replaces it', function () {
    $keys = VAPID::createVapidKeys();
    $driver = new PushDriver(
        ['subject' => 'mailto:ops@example.test', 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']],
        app(RecipientLocale::class),
    );

    $client = (new ReflectionMethod($driver, 'client'))->invoke($driver);

    expect($client)->toBeInstanceOf(WebPush::class);
});
