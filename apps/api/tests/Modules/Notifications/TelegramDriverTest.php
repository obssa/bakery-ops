<?php

declare(strict_types=1);

use App\Modules\Notifications\Contracts\Notifier;
use App\Modules\Notifications\Jobs\DeliverNotification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationEndpoint;
use App\Shared\Models\Branch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Support\NotificationDrivers;
use Tests\Support\NotificationEvents;

pest()->group('module:notifications', 'feature');

/*
 * Step 2.5b: the Telegram driver posts the Bot API's sendMessage through Laravel's Http client with the recipient's language.
 * Every case here fakes the client (Http::fake and preventStrayRequests): CI never reaches api.telegram.org. The bot token
 * is in the URL of every Bot API call, so it must never reach a delivery row's error, whatever the failure.
 */

beforeEach(function () {
    seedRoles();
    NotificationDrivers::allOff();
    Http::preventStrayRequests();
    $this->telegram = NotificationDrivers::telegram();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager', ['locale' => 'am']);
    withActor($this->admin->id);
    $this->tell = function ($user, string $chat = '4815162342'): NotificationDelivery {
        NotificationEndpoint::factory()->telegram()->create(['branch_id' => $this->branch->id, 'user_id' => $user->id, 'address' => $chat]);
        app(Notifier::class)->send(new Collection([$user]), 'stock_out', NotificationEvents::stockOut($this->branch->id, 11, 4, 21)->payload());

        return NotificationDelivery::query()->latest('id')->firstOrFail();
    };
});

it('posts sendMessage to the Bot API with the chat and the text in English for an English reader', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]])]);

    $delivery = ($this->tell)($this->admin);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === "https://api.telegram.org/bot{$this->telegram['token']}/sendMessage"
            && $request['chat_id'] === '4815162342'
            && $request['text'] === "Black forest cake is out of stock\nNone left at Display case.";
    });
    expect($delivery->fresh()->status)->toBe('sent')->and($delivery->fresh()->attempts)->toBe(1);
});

it('writes the text in Amharic for an Amharic reader', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    ($this->tell)($this->manager, '99001');

    Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '99001'
        && $request['text'] === "ብላክ ፎረስት ኬክ አልቋል\nበየማሳያ ሣጥን ምንም አልቀረም።");
});

it('marks the delivery gone and revokes the chat when the bot was blocked or the chat no longer exists', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);

    $delivery = ($this->tell)($this->admin);

    expect($delivery->fresh()->status)->toBe('gone')
        ->and($delivery->endpoint->fresh()->revoked_at)->not->toBeNull();
});

it('records a server error on the delivery, keeps it for a retry, and never stores the bot token', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway'], 502)]);

    $delivery = ($this->tell)($this->admin);

    $row = $delivery->fresh();
    expect($row->status)->toBe('pending')
        ->and($row->attempts)->toBe(1)
        ->and($row->last_error)->toContain('502')->toContain('Bad Gateway')
        ->and($row->last_error)->not->toContain($this->telegram['token']);
});

it('masks the bot token when the connection itself fails', function () {
    Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException("cURL error 28: timed out for https://api.telegram.org/bot{$this->telegram['token']}/sendMessage")]);

    $delivery = ($this->tell)($this->admin);

    $row = $delivery->fresh();
    expect($row->status)->toBe('pending')
        ->and($row->last_error)->toContain('timed out')
        ->and($row->last_error)->not->toContain($this->telegram['token']);
});

it('answers a rate limit as a failure to retry, not as a gone chat', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 5'], 429)]);

    $delivery = ($this->tell)($this->admin);

    expect($delivery->fresh()->status)->toBe('pending')->and($delivery->endpoint->fresh()->revoked_at)->toBeNull();
});

it('sends nothing when telegram is off', function () {
    Http::fake();
    NotificationEndpoint::factory()->telegram()->create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id]);
    NotificationDrivers::allOff();

    app(Notifier::class)->send(new Collection([$this->admin]), 'stock_out', NotificationEvents::stockOut($this->branch->id, 11, 4, 21)->payload());

    Http::assertNothingSent();
    expect(NotificationDelivery::query()->count())->toBe(0);
});

it('can be run again for the same delivery without a second message once it was sent', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $delivery = ($this->tell)($this->admin);

    DeliverNotification::dispatchSync($delivery->id, $this->branch->id);

    Http::assertSentCount(1);
});
