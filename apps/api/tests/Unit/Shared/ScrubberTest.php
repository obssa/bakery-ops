<?php

declare(strict_types=1);

use App\Shared\Observability\SentryScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Sentry\UserDataBag;

/*
 * Phase 5 step 5.5a (ADR 0021, plan 10.2 D21): Sentry's before_send. Nothing personal leaves the host: PINs, passwords, tokens, the
 * Authorization and Cookie headers, phone numbers, the signature= and expires= of a signed URL, and the whole body of an identity route are
 * gone, wherever they sit (the request, the exception's message, the breadcrumbs, extra, tags, contexts, the frames' arguments). What helps
 * to find a bug (the path, harmless fields, amounts, order numbers, dates) stays.
 */

/** @return array<string, mixed> */
function scrubbedJson(Event $event): array
{
    $out = SentryScrubber::beforeSend($event, null);
    expect($out)->toBeInstanceOf(Event::class);

    return [
        'message' => $out->getMessage(),
        'request' => $out->getRequest(),
        'exceptions' => array_map(fn (ExceptionDataBag $e) => [
            'value' => $e->getValue(),
            'vars' => array_map(fn (Frame $f) => $f->getVars(), $e->getStacktrace()?->getFrames() ?? []),
        ], $out->getExceptions()),
        'breadcrumbs' => array_map(fn (Breadcrumb $b) => ['message' => $b->getMessage(), 'metadata' => $b->getMetadata()], $out->getBreadcrumbs()),
        'extra' => $out->getExtra(),
        'tags' => $out->getTags(),
        'contexts' => $out->getContexts(),
        'user' => $out->getUser() === null ? null : [
            'id' => $out->getUser()->getId(),
            'email' => $out->getUser()->getEmail(),
            'ip_address' => $out->getUser()->getIpAddress(),
            'username' => $out->getUser()->getUsername(),
        ],
        'transaction' => $out->getTransaction(),
    ];
}

function scrubbedText(string $text): string
{
    return SentryScrubber::scrubText($text);
}

it('removes PINs, passwords, tokens and phone numbers from the request body at any depth, and keeps the rest', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://shop.example.test/api/v1/orders/9/payments',
        'method' => 'POST',
        'data' => [
            'pin' => '402913',
            'password' => 'Pa55-SECRET-word',
            'current_password' => 'Old-SECRET-pass',
            'short_code' => '7788',
            'token' => 'TOKENsecret0003',
            'phone' => '+251911223344',
            'rows' => [['amount' => '1250.00', 'reference_no' => 'FT26302AB12C', 'customer_phone' => '0911223344', 'note' => 'call +251911223344']],
            'channel_code' => 'telebirr',
        ],
    ]);

    $json = json_encode(scrubbedJson($event)['request'], JSON_UNESCAPED_SLASHES);

    foreach (['402913', 'Pa55-SECRET-word', 'Old-SECRET-pass', '7788', 'TOKENsecret0003', '251911223344', '0911223344'] as $secret) {
        expect($json)->not->toContain($secret);
    }
    expect($json)->toContain('1250.00')->toContain('FT26302AB12C')->toContain('telebirr')->toContain('/api/v1/orders/9/payments');
})->group('unit', 'module:shared');

it('drops the whole body of an identity route', function (string $path) {
    $event = Event::createEvent();
    $event->setRequest(['url' => "https://shop.example.test{$path}", 'method' => 'POST', 'data' => ['name_en' => 'Abebe Kebede', 'station_ids' => [1, 2]]]);

    $request = scrubbedJson($event)['request'];

    expect($request['data'])->toBe(SentryScrubber::FILTERED)
        ->and(json_encode($request))->not->toContain('Abebe');
})->with(['/api/v1/login', '/api/v1/logout', '/api/v1/me', '/api/v1/short-code/verify', '/api/v1/users', '/api/v1/users/12', '/api/v1/users/12/short-code/reset', '/api/v1/devices', '/api/v1/devices/3/rename', '/api/v1/stations/4'])
    ->group('unit', 'module:shared');

it('keeps the body of any other route (scrubbed)', function () {
    $event = Event::createEvent();
    $event->setRequest(['url' => 'https://shop.example.test/api/v1/catalog/items', 'method' => 'POST', 'data' => ['name_en' => 'Sourdough']]);

    expect(scrubbedJson($event)['request']['data'])->toBe(['name_en' => 'Sourdough']);
})->group('unit', 'module:shared');

it('removes the Authorization and Cookie headers, the cookies and the server environment', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://shop.example.test/api/v1/orders',
        'headers' => [
            'Authorization' => ['Bearer 7|BEARERsecretTOKENvalue0123456789abcdefghijkl'],
            'authorization' => 'Bearer 8|LOWERsecret',
            'Cookie' => ['bakery_session=COOKIEsecret0004'],
            'X-XSRF-TOKEN' => ['XSRFsecret'],
            'Idempotency-Key' => ['7f0c3a8e-2b1d-4c5e-9f8a-1b2c3d4e5f60'],
            'Accept' => ['application/json'],
        ],
        'cookies' => ['bakery_session' => 'COOKIEsecret0004'],
        'env' => ['REMOTE_ADDR' => '203.0.113.9'],
    ]);

    $request = scrubbedJson($event)['request'];
    $json = json_encode($request, JSON_UNESCAPED_SLASHES);

    expect($json)->not->toContain('BEARERsecret')->not->toContain('LOWERsecret')->not->toContain('COOKIEsecret')->not->toContain('XSRFsecret')
        ->not->toContain('203.0.113.9')
        ->and($request)->not->toHaveKey('cookies')->not->toHaveKey('env')
        ->and($json)->toContain('application/json')->toContain('7f0c3a8e-2b1d-4c5e-9f8a-1b2c3d4e5f60');
})->group('unit', 'module:shared');

it('removes signature= and expires= from the URL and the query string', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://shop.example.test/api/v1/receipts/5?expires=1893456000&signature=SIGsecret0001&lang=am',
        'query_string' => 'expires=1893456000&signature=SIGsecret0001&lang=am',
    ]);

    $request = scrubbedJson($event)['request'];

    expect(json_encode($request))->not->toContain('SIGsecret')->not->toContain('1893456000')
        ->and($request['url'])->toContain('/api/v1/receipts/5')->toContain('lang=am')
        ->and($request['query_string'])->toContain('lang=am');
})->group('unit', 'module:shared');

it('scrubs the exception messages and clears the arguments the frames captured', function () {
    $frame = new Frame('App\Modules\Identity\Support\Pins::check', 'app/Modules/Identity/Support/Pins.php', 41, null, null, ['pin' => '402913', 'user' => 12]);
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag(
        new RuntimeException('could not reach +251911223344 at http://h/r?expires=1893456000&signature=SIGsecret0001 (password=Pa55-SECRET-word, token: TOKENsecret0003) with Bearer 7|BEARERsecretTOKENvalue0123456789abcdefghijkl'),
        new Stacktrace([$frame]),
    )]);

    $exceptions = scrubbedJson($event)['exceptions'];
    $json = json_encode($exceptions);

    foreach (['251911223344', 'SIGsecret', '1893456000', 'Pa55-SECRET-word', 'TOKENsecret0003', 'BEARERsecret', '402913'] as $secret) {
        expect($json)->not->toContain($secret);
    }
    expect($exceptions[0]['value'])->toContain('could not reach')
        ->and($exceptions[0]['vars'])->toBe([[]]);
})->group('unit', 'module:shared');

it('scrubs breadcrumbs, the message, extra, tags and contexts, and keeps only the user id', function () {
    $event = Event::createEvent();
    $event->setMessage('payment failed for 0911223344, pin=402913');
    $event->setBreadcrumb([
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'login attempt by +251911223344', ['password' => 'Pa55-SECRET-word', 'order_no' => 'A-0042']),
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', null, ['url' => 'https://shop.example.test/api/v1/files/7?expires=1893456000&signature=SIGsecret0001', 'status_code' => 200]),
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'db.sql.query', 'select * from users where phone = ?', ['bindings' => ['+251911223344']]),
    ]);
    $event->setExtra(['caller' => ['phone_number' => '+251911223344', 'day' => '2026-10-29']]);
    $event->setTags(['short_code' => '7788', 'station' => 'bar']);
    $event->setContext('laravel', ['token' => 'TOKENsecret0003', 'route' => 'payments.store']);
    $event->setUser(new UserDataBag(12, 'someone@example.test', '203.0.113.9', 'abebe'));
    $event->setTransaction('/api/v1/receipts/5?signature=SIGsecret0001');

    $out = scrubbedJson($event);
    $json = json_encode($out);

    foreach (['0911223344', '251911223344', '402913', 'Pa55-SECRET-word', 'SIGsecret', '1893456000', '7788', 'TOKENsecret0003', 'someone@example.test', '203.0.113.9', 'abebe'] as $secret) {
        expect($json)->not->toContain($secret);
    }
    expect($json)->toContain('A-0042')->toContain('2026-10-29')->toContain('payments.store')->toContain('bar')
        ->and($out['user'])->toBe(['id' => 12, 'email' => null, 'ip_address' => null, 'username' => null]);
})->group('unit', 'module:shared');

it('finds phone numbers in free text and leaves amounts, ids and dates alone', function () {
    expect(scrubbedText('call +251 91 122 3344 or 0911223344 or 251711223344 or +44 20 7946 0958'))
        ->not->toContain('3344')->not->toContain('7946')
        ->and(scrubbedText('order A-0042 paid 1250.00 ETB on 2026-10-29 at 14:05, payment 123456, item 98765'))
        ->toBe('order A-0042 paid 1250.00 ETB on 2026-10-29 at 14:05, payment 123456, item 98765');
})->group('unit', 'module:shared');
