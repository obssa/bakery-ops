<?php

declare(strict_types=1);

use App\Shared\Http\Middleware\Idempotency;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
 * Rule 7 / ADR 0003 at the route level: every write route under api/v1 carries the idempotency middleware unless
 * public, and every write route is in tests/Support/write_endpoints.php, whose entries are replayed by
 * assertIdempotent(). A write route that is not registered fails here.
 */

$writeRoutes = function (): array {
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RouteDefinition $route */
        if (! str_starts_with($route->uri(), 'api/v1') || in_array($route->uri(), publicRoutes(), true)) {
            continue;
        }
        $methods = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);
        if ($methods !== []) {
            $routes[] = $route;
        }
    }

    return $routes;
};

it('every write route under api/v1 carries the idempotency middleware', function () use ($writeRoutes) {
    $missing = [];
    foreach ($writeRoutes() as $route) {
        $resolved = app('router')->gatherRouteMiddleware($route);
        if (! in_array(Idempotency::class, $resolved, true) && ! in_array('idempotency', $route->gatherMiddleware(), true)) {
            $missing[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }
    expect($missing)->toBe([]);
})->group('invariants');

it('every write route under api/v1 is registered in tests/Support/write_endpoints.php', function () use ($writeRoutes) {
    $registered = array_map(
        fn (array $e) => strtoupper((string) $e['method']).' '.ltrim((string) $e['uri'], '/'),
        writeEndpoints(),
    );
    $unregistered = [];
    foreach ($writeRoutes() as $route) {
        foreach (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) as $method) {
            if (! in_array("{$method} {$route->uri()}", $registered, true)) {
                $unregistered[] = "{$method} {$route->uri()}";
            }
        }
    }
    expect($unregistered)->toBe([]);
})->group('invariants');

it('replays every registered write endpoint idempotently', function () {
    foreach (writeEndpoints() as $entry) {
        if (isset($entry['setup'])) {
            ($entry['setup'])($this);
        }
        assertIdempotent($this, (string) $entry['method'], (string) $entry['uri'], ($entry['payload'])(), (string) $entry['table']);
    }
    expect(true)->toBeTrue();
})->group('invariants');

it('idempotency is a registered middleware alias', function () {
    $aliases = app('router')->getMiddleware();

    expect($aliases['idempotency'] ?? null)->toBe(Idempotency::class);
})->group('invariants', 'module:shared');
