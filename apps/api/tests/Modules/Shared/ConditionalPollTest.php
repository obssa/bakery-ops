<?php

declare(strict_types=1);

use App\Shared\Live\ConditionalPoll;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;

/*
 * Cheap polls (ADR 0009 decision 3): a polled feed answers with an ETag computed from a few cheap numbers (a max
 * cursor, a count; OverlapCursor::fingerprint), and a request whose If-None-Match names it gets 304 with no body,
 * before the feed's rows are loaded. Every answer says Cache-Control: no-cache, so the browser and Cloudflare
 * revalidate instead of serving a copy. Step 1.5's tickets and orders feeds are built on this helper.
 */

beforeEach(function () {
    $this->version = 1;
    $this->built = 0;
    Route::get('/_shared-test/feed', function (Request $request) {
        $etag = ConditionalPoll::etag('probe', ['version' => $this->version]);

        return ConditionalPoll::respond($request, $etag, function () {
            $this->built++;

            return ['data' => [['id' => 1, 'version' => $this->version]], 'cursor' => $this->version];
        });
    });
});

it('answers 200 with the body, a quoted ETag and Cache-Control: no-cache', function () {
    $response = $this->getJson('/_shared-test/feed');

    $response->assertOk()->assertJsonPath('cursor', 1)->assertJsonPath('data.0.id', 1);
    expect($response->headers->get('ETag'))->toMatch('/\A"probe-[0-9a-f]{20}"\z/')
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($this->built)->toBe(1);
})->group('module:shared', 'feature');

it('answers 304 with no body when If-None-Match names the current ETag, without building the feed', function () {
    $etag = (string) $this->getJson('/_shared-test/feed')->headers->get('ETag');

    $response = $this->getJson('/_shared-test/feed', ['If-None-Match' => $etag]);

    $response->assertStatus(304);
    expect($response->getContent())->toBe('')
        ->and($response->headers->get('ETag'))->toBe($etag)
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($this->built)->toBe(1);
})->group('module:shared', 'feature');

it('hands out a new ETag after a change, and the old one no longer matches', function () {
    $old = (string) $this->getJson('/_shared-test/feed')->headers->get('ETag');

    $this->version = 2;
    $response = $this->getJson('/_shared-test/feed', ['If-None-Match' => $old]);

    $response->assertOk()->assertJsonPath('cursor', 2);
    expect($response->headers->get('ETag'))->not->toBe($old)
        ->and($this->getJson('/_shared-test/feed', ['If-None-Match' => (string) $response->headers->get('ETag')])->getStatusCode())->toBe(304);
})->group('module:shared', 'feature');

it('matches a weak copy of the ETag (Cloudflare weakens ETags it compresses), a list, and *', function () {
    $etag = (string) $this->getJson('/_shared-test/feed')->headers->get('ETag');

    expect($this->getJson('/_shared-test/feed', ['If-None-Match' => 'W/'.$etag])->getStatusCode())->toBe(304)
        ->and($this->getJson('/_shared-test/feed', ['If-None-Match' => '"other", '.$etag])->getStatusCode())->toBe(304)
        ->and($this->getJson('/_shared-test/feed', ['If-None-Match' => '*'])->getStatusCode())->toBe(304)
        ->and($this->getJson('/_shared-test/feed', ['If-None-Match' => '"probe-0000"'])->getStatusCode())->toBe(200)
        ->and($this->getJson('/_shared-test/feed', ['If-None-Match' => trim($etag, '"')])->getStatusCode())->toBe(200);
})->group('module:shared', 'feature');

it('derives the ETag from the scope and the parts only', function () {
    $a = ConditionalPoll::etag('tickets', ['station' => 5, 'max' => 120, 'pending' => 2]);

    expect(ConditionalPoll::etag('tickets', ['station' => 5, 'max' => 120, 'pending' => 2]))->toBe($a)
        ->and(ConditionalPoll::etag('tickets', ['station' => 5, 'max' => 121, 'pending' => 2]))->not->toBe($a)
        ->and(ConditionalPoll::etag('tickets', ['station' => 5, 'max' => 120, 'pending' => 3]))->not->toBe($a)
        ->and(ConditionalPoll::etag('orders', ['station' => 5, 'max' => 120, 'pending' => 2]))->not->toBe($a)
        ->and(ConditionalPoll::etag('tickets', ['station' => 5, 'max' => null, 'pending' => 2]))->not->toBe($a)
        ->and($a)->toStartWith('"tickets-')
        ->and(fn () => ConditionalPoll::etag('Bad Scope"', []))->toThrow(InvalidArgumentException::class);
})->group('module:shared', 'unit');

it('passes a resource or a response through and still stamps the ETag', function () {
    Route::get('/_shared-test/resource', fn (Request $request) => ConditionalPoll::respond(
        $request,
        ConditionalPoll::etag('probe', ['kind' => 'resource']),
        fn () => JsonResource::make(['id' => 9]),
    ));
    Route::get('/_shared-test/response', fn (Request $request) => ConditionalPoll::respond(
        $request,
        ConditionalPoll::etag('probe', ['kind' => 'response']),
        fn () => response()->json(['id' => 10], 200),
    ));

    $resource = $this->getJson('/_shared-test/resource');
    $response = $this->getJson('/_shared-test/response');

    $resource->assertOk()->assertJsonPath('data.id', 9);
    $response->assertOk()->assertJsonPath('id', 10);
    expect($resource->headers->get('ETag'))->toBe(ConditionalPoll::etag('probe', ['kind' => 'resource']))
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache');
})->group('module:shared', 'feature');

it('lets the web shell on another origin read the ETag (CORS exposes it)', function () {
    config(['cors.allowed_origins' => ['http://localhost:3000']]);

    $response = $this->getJson('/api/v1/health', ['Origin' => 'http://localhost:3000']);

    expect(config('cors.exposed_headers'))->toContain('ETag')
        ->and((string) $response->headers->get('Access-Control-Expose-Headers'))->toContain('ETag');
})->group('module:shared', 'feature');
