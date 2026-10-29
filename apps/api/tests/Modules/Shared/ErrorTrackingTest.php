<?php

declare(strict_types=1);

use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Shared\Observability\ErrorTracking;
use App\Shared\Observability\SentryScrubber;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;
use Sentry\Laravel\Tracing\ServiceProvider as SentryTracingServiceProvider;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Tests\Support\FakeSentryIngest;

/*
 * Phase 5 step 5.5a (ADR 0021; plan 10.2 Q1 and D21): Sentry support is built and off. While SENTRY_LARAVEL_DSN is unset there is no Sentry
 * provider, no client and no network. With a DSN, an unexpected exception reaches the ingest (here a fake one, handed to the SDK as its HTTP
 * client, so nothing leaves the process) with nothing personal left in it: no PIN, password, token, Authorization or Cookie header, phone
 * number, signed-URL parameter, and no body at all from an identity route. A refusal never reaches it. Traces stay off.
 */

uses()->group('module:shared', 'feature');

// a made-up DSN: the .test top-level domain never resolves, and the fake client answers before any network anyway
const FAKE_SENTRY_DSN = 'https://0123456789abcdef0123456789abcdef@ingest.sentry.example.test/42';

// What the requests below carry and must never reach the ingest. Each is written in two halves: Sentry sends the source lines around every
// in-app frame (this file's among them, which is the repository's own code), so a secret spelt out here would reach the fake ingest that way
// and hide what the test is about: whether the request's values, the message and the breadcrumbs are scrubbed.
const SENTRY_SECRETS = [
    'pin' => '402'.'913',
    'password' => 'Pa55-'.'SECRET-word',
    'token' => 'TOKEN'.'secret0003',
    'bearer' => 'BEARER'.'secretTOKENvalue0123456789abcdefghijkl',
    'cookie' => 'COOKIE'.'secret0004',
    'phone' => '2519'.'11223344',
    'local phone' => '09'.'11223344',
    'signature' => 'SIG'.'secret',
    'expires' => '18934'.'56000',
    'a name' => 'Abebe '.'Kebede',
];

/** Fails the test if any secret (or $alsoAbsent) reached the ingest anywhere in what would have been sent. */
function expectNothingPersonalIn(FakeSentryIngest $ingest, string ...$alsoAbsent): void
{
    $raw = $ingest->raw();
    foreach (SENTRY_SECRETS + $alsoAbsent as $what => $secret) {
        expect(str_contains($raw, $secret))->toBeFalse("{$what} reached the ingest");
    }
}

afterEach(function () {
    // the SDK keeps its current hub in a static: never let one test's client reach the next
    SentrySdk::setCurrentHub(new Hub);
});

/** Turns error tracking on for this test as the server .env would (the DSN in config), with the fake ingest as the SDK's HTTP client. */
function turnSentryOn(FakeSentryIngest $ingest, ?string $dsn = FAKE_SENTRY_DSN): bool
{
    config(['sentry.dsn' => $dsn, 'sentry.http_client' => $ingest]);

    return ErrorTracking::register(app());
}

it('has no Sentry provider, no client and sends nothing while SENTRY_LARAVEL_DSN is unset', function () {
    $ingest = new FakeSentryIngest;
    Route::post('/api/v1/__sentry-test/boom', fn () => throw new RuntimeException('boom'));

    expect(config('sentry.dsn'))->toBeNull()
        ->and(ErrorTracking::enabled())->toBeFalse()
        ->and(app(PackageManifest::class)->providers())->not->toContain(SentryServiceProvider::class)->not->toContain(SentryTracingServiceProvider::class)
        ->and(app()->getProviders(SentryServiceProvider::class))->toBe([])
        ->and(app()->bound(HubInterface::class))->toBeFalse()
        ->and(turnSentryOn($ingest, null))->toBeFalse()
        ->and(turnSentryOn($ingest, ''))->toBeFalse()
        ->and(app()->getProviders(SentryServiceProvider::class))->toBe([]);

    report(new RuntimeException('the disk is full'));
    $this->postJson('/api/v1/__sentry-test/boom')->assertStatus(500);

    expect($ingest->envelopes)->toBe([])
        ->and(SentrySdk::getCurrentHub()->getClient())->toBeNull();
});

it('sends an unexpected exception to the ingest with nothing personal left in it', function () {
    $ingest = new FakeSentryIngest;
    expect(turnSentryOn($ingest))->toBeTrue()
        ->and(ErrorTracking::enabled())->toBeTrue();

    $s = SENTRY_SECRETS;
    $signed = "expires={$s['expires']}&signature={$s['signature']}0001";
    Route::post('/api/v1/__sentry-test/boom', function () use ($s, $signed) {
        Log::warning("before the failure: the customer on +{$s['phone']} asked twice, pin={$s['pin']}");
        throw new RuntimeException("could not reach +{$s['phone']} at http://h/api/v1/receipts/5?{$signed} (password={$s['password']})");
    });

    $this->withHeaders([
        'Authorization' => "Bearer 7|{$s['bearer']}",
        'Cookie' => "bakery_session={$s['cookie']}",
    ])->postJson("/api/v1/__sentry-test/boom?{$signed}&page=2", [
        'pin' => $s['pin'],
        'password' => $s['password'],
        'token' => $s['token'],
        'phone' => "+{$s['phone']}",
        'note' => "call {$s['local phone']} after six",
        'kept' => 'visible-value',
    ])->assertStatus(500);

    $events = $ingest->events();
    expect($events)->toHaveCount(1);
    expectNothingPersonalIn($ingest);

    $event = $events[0];
    expect($event['exception']['values'][0]['type'] ?? null)->toBe(RuntimeException::class)
        ->and($event['exception']['values'][0]['value'] ?? null)->toStartWith('could not reach [phone] at http://h/api/v1/receipts/5')
        ->and($event['request']['url'] ?? null)->toEndWith('/api/v1/__sentry-test/boom?page=2')
        ->and($event['request']['headers']['authorization'] ?? null)->toBe(SentryScrubber::FILTERED)
        ->and($event['request']['data'] ?? null)->toMatchArray(['pin' => SentryScrubber::FILTERED, 'note' => 'call [phone] after six', 'kept' => 'visible-value'])
        ->and(collect($event['breadcrumbs']['values'] ?? [])->pluck('message')->implode(' '))->toContain('asked twice, pin='.SentryScrubber::FILTERED)
        ->and($event['user']['ip_address'] ?? null)->toBeNull()
        ->and($event)->not->toHaveKey('spans');
});

it('sends nothing from the body of an identity route', function () {
    $ingest = new FakeSentryIngest;
    turnSentryOn($ingest);
    Route::post('/api/v1/users/sentry-test-boom', fn () => throw new RuntimeException('identity boom'));
    $kept = 'visible'.'-value';

    $this->postJson('/api/v1/users/sentry-test-boom', ['name_en' => SENTRY_SECRETS['a name'], 'kept' => $kept])->assertStatus(500);

    $events = $ingest->events();
    expect($events)->toHaveCount(1)
        ->and($events[0]['exception']['values'][0]['value'] ?? null)->toBe('identity boom')
        ->and($events[0]['request']['data'] ?? null)->toBe(SentryScrubber::FILTERED);
    expectNothingPersonalIn($ingest, $kept);
});

it('never sends a refusal, even with a DSN', function () {
    $ingest = new FakeSentryIngest;
    turnSentryOn($ingest);
    Route::post('/api/v1/__sentry-test/refuse', fn () => throw PaymentError::settled());

    $this->postJson('/api/v1/__sentry-test/refuse')->assertStatus(409);
    report(OrderError::empty());

    expect($ingest->envelopes)->toBe([]);
});

it('keeps traces, profiles, logs and personal data off in the config', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.traces_sample_rate'))->toBeNull()
        ->and(config('sentry.profiles_sample_rate'))->toBeNull()
        ->and(config('sentry.enable_logs'))->toBeFalse()
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.before_send'))->toBeCallable();
});

it('documents both DSNs, empty, in .env.example', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^SENTRY_LARAVEL_DSN=$/m')
        ->and($example)->toMatch('/^NEXT_PUBLIC_SENTRY_DSN=$/m');
});
