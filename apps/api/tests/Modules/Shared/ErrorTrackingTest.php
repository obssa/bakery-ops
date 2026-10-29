<?php

declare(strict_types=1);

use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Shared\Observability\ErrorTracking;
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

// what the requests below carry and must never reach the ingest
const SENTRY_SECRETS = [
    'pin' => '402913',
    'password' => 'Pa55-SECRET-word',
    'token' => 'TOKENsecret0003',
    'bearer' => 'BEARERsecretTOKENvalue0123456789abcdefghijkl',
    'cookie' => 'COOKIEsecret0004',
    'phone (international)' => '251911223344',
    'phone (local)' => '0911223344',
    'signature' => 'SIGsecret',
    'expires' => '1893456000',
];

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

    Route::post('/api/v1/__sentry-test/boom', function () {
        Log::warning('before the failure: the customer on +251911223344 asked twice, pin=402913');
        throw new RuntimeException('could not reach +251911223344 at http://h/api/v1/receipts/5?expires=1893456000&signature=SIGsecret0001 (password=Pa55-SECRET-word)');
    });

    $this->withHeaders([
        'Authorization' => 'Bearer 7|BEARERsecretTOKENvalue0123456789abcdefghijkl',
        'Cookie' => 'bakery_session=COOKIEsecret0004',
    ])->postJson('/api/v1/__sentry-test/boom?expires=1893456000&signature=SIGsecret0002&page=2', [
        'pin' => '402913',
        'password' => 'Pa55-SECRET-word',
        'token' => 'TOKENsecret0003',
        'phone' => '+251911223344',
        'note' => 'call 0911223344 after six',
        'kept' => 'visible-value',
    ])->assertStatus(500);

    $events = $ingest->events();
    expect($events)->toHaveCount(1)
        ->and($events[0]['exception']['values'][0]['type'] ?? null)->toBe(RuntimeException::class);

    $raw = $ingest->raw();
    foreach (SENTRY_SECRETS as $what => $secret) {
        expect($raw)->not->toContain($secret, "the {$what} reached the ingest");
    }
    expect($raw)->toContain('__sentry-test/boom')->toContain('page=2')->toContain('visible-value')
        ->and($events[0]['user']['ip_address'] ?? null)->toBeNull()
        ->and($events[0])->not->toHaveKey('spans');
});

it('sends nothing from the body of an identity route', function () {
    $ingest = new FakeSentryIngest;
    turnSentryOn($ingest);
    Route::post('/api/v1/users/sentry-test-boom', fn () => throw new RuntimeException('identity boom'));

    $this->postJson('/api/v1/users/sentry-test-boom', ['name_en' => 'Abebe Kebede', 'kept' => 'visible-value'])->assertStatus(500);

    expect($ingest->events())->toHaveCount(1)
        ->and($ingest->raw())->toContain('identity boom')->not->toContain('Abebe')->not->toContain('visible-value');
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
