<?php

declare(strict_types=1);

/*
 * The web shell is a static export served by LiteSpeed under /app/ on the API's own origin (ADR 0006, step 0.7):
 * the site root sends people there, and the API answers cross-origin requests only from the origins listed in
 * CORS_ALLOWED_ORIGINS (http://localhost:3000 locally and in CI), never from everywhere (ADR 0007). In production
 * the list is empty: the shell and the API share one origin, so no CORS header is ever needed.
 */

it('redirects the site root to the web shell under /app/ without starting a session', function () {
    $response = $this->get('/');

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe('/app/')
        ->and($response->headers->getCookies())->toBe([]);
})->group('invariants', 'feature');

it('reads the CORS allowlist from CORS_ALLOWED_ORIGINS and defaults to no origin at all', function () {
    $load = fn (): array => require config_path('cors.php');

    putenv('CORS_ALLOWED_ORIGINS');
    try {
        expect($load()['allowed_origins'])->toBe([])
            ->and($load()['paths'])->toBe(['api/*']);

        putenv('CORS_ALLOWED_ORIGINS= http://localhost:3000 ,http://127.0.0.1:3000,');
        expect($load()['allowed_origins'])->toBe(['http://localhost:3000', 'http://127.0.0.1:3000'])
            ->and($load()['allowed_origins_patterns'])->toBe([])
            ->and($load()['supports_credentials'])->toBeFalse();
    } finally {
        putenv('CORS_ALLOWED_ORIGINS');
    }
})->group('invariants', 'feature');

it('answers a preflight from a listed origin only, and from none when the list is empty (production)', function () {
    $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/v1/items', [], [], [], [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type, idempotency-key',
    ]);

    config(['cors.allowed_origins' => ['http://localhost:3000', 'http://127.0.0.1:3000']]);
    $preflight('http://localhost:3000')->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    $preflight('https://elsewhere.example')->assertHeaderMissing('Access-Control-Allow-Origin');

    config(['cors.allowed_origins' => []]);
    $preflight('http://localhost:3000')->assertHeaderMissing('Access-Control-Allow-Origin');
    $this->getJson('/api/v1/health', ['Origin' => 'https://elsewhere.example'])
        ->assertOk()
        ->assertHeaderMissing('Access-Control-Allow-Origin');
})->group('invariants', 'feature');
