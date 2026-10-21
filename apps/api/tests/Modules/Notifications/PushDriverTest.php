<?php

declare(strict_types=1);

use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Drivers\DriverRegistry;
use App\Modules\Notifications\Drivers\PushDriver;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Shared\Models\Branch;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Tests\Support\NotificationDrivers;
use Tests\Support\NotificationEvents;

pest()->group('module:notifications', 'feature');

/*
 * Step 2.5b: the push driver sends a VAPID web push through minishlink/web-push. The library's HTTP client is replaced by a
 * Guzzle mock handler (the container alias notifications.push.client), so nothing leaves the test process; the subscription
 * is a real key pair generated at run time, so the library really encrypts the payload for it.
 */

beforeEach(function () {
    seedRoles();
    NotificationDrivers::allOff();
    $this->keys = NotificationDrivers::push();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);

    $this->requests = [];
    $this->respond = function (Response ...$responses): void {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));
        app()->bind('notifications.push.client', fn ($app, array $parameters): WebPush => new WebPush(
            ['VAPID' => $parameters['vapid']], [], new Client(['handler' => $stack, 'http_errors' => false]),
        ));
    };
    $this->subscribe = function (string $url = 'https://push.example.test/send/abc123'): NotificationEndpoint {
        $browser = VAPID::createVapidKeys();

        return NotificationEndpoint::factory()->push()->create([
            'branch_id' => $this->branch->id, 'user_id' => $this->admin->id,
            'address' => json_encode([
                'endpoint' => $url,
                'keys' => ['p256dh' => $browser['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')],
            ], JSON_THROW_ON_ERROR),
        ]);
    };
    $this->tell = function (): NotificationDelivery {
        app(Notifier::class)->send(new Collection([$this->admin]), 'stock_out', NotificationEvents::stockOut($this->branch->id, 11, 4, 21)->payload());

        return NotificationDelivery::query()->latest('id')->firstOrFail();
    };
});

it('posts an encrypted, VAPID-signed message to the subscription endpoint and marks the delivery sent', function () {
    ($this->respond)(new Response(201));
    $endpoint = ($this->subscribe)();

    $delivery = ($this->tell)();

    expect($this->requests)->toHaveCount(1);
    $request = $this->requests[0]['request'];
    expect((string) $request->getUri())->toBe('https://push.example.test/send/abc123')
        ->and($request->getMethod())->toBe('POST')
        ->and($request->getHeaderLine('Authorization'))->toStartWith('vapid t=')->toContain(', k=')
        ->and($request->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($request->getHeaderLine('TTL'))->not->toBe('')
        ->and((string) $request->getBody())->not->toContain('Black forest cake');
    expect($delivery->fresh()->status)->toBe('sent')
        ->and($delivery->fresh()->attempts)->toBe(1)
        ->and($endpoint->fresh()->last_success_at)->not->toBeNull();
});

it('puts the title, the body, the link and the type in the message, in the language of the recipient', function () {
    $driver = app(DriverRegistry::class)->for('push');
    expect($driver)->toBeInstanceOf(PushDriver::class);
    ($this->respond)(new Response(201));
    ($this->subscribe)();
    $delivery = ($this->tell)();

    $message = json_decode($driver->payloadFor($delivery->notification, 'am'), true, 512, JSON_THROW_ON_ERROR);

    expect($message)->toBe([
        'title' => 'ብላክ ፎረስት ኬክ አልቋል',
        'body' => 'በየማሳያ ሣጥን ምንም አልቀረም።',
        'url' => '/inventory?item=11&location=4',
        'tag' => 'stock_out',
        'severity' => 'critical',
    ]);
});

it('marks the delivery gone and revokes the subscription when the push service answers 410 or 404', function (int $status) {
    ($this->respond)(new Response($status));
    $endpoint = ($this->subscribe)();

    $delivery = ($this->tell)();

    expect($delivery->fresh()->status)->toBe('gone')->and($endpoint->fresh()->revoked_at)->not->toBeNull();
})->with([410, 404]);

it('records any other refusal on the delivery and keeps it for a retry', function () {
    ($this->respond)(new Response(503, [], 'Service Unavailable'));
    $endpoint = ($this->subscribe)();

    $delivery = ($this->tell)();

    $row = $delivery->fresh();
    expect($row->status)->toBe('pending')
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->toContain('503')
        ->and($endpoint->fresh()->revoked_at)->toBeNull()
        ->and(Notification::query()->count())->toBe(1);
});

it('never puts the VAPID private key in an error', function () {
    ($this->respond)(new Response(500, [], $this->keys['privateKey']));
    ($this->subscribe)();

    $delivery = ($this->tell)();

    expect((string) $delivery->fresh()->last_error)->not->toContain($this->keys['privateKey']);
});
