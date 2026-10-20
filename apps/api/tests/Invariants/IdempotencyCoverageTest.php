<?php

declare(strict_types=1);

use App\Shared\Http\Middleware\Idempotency;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
 * Rule 7 / ADR 0003 at the route level: every write route under api/v1 carries the idempotency middleware unless
 * public or read-like (a write method that writes nothing, tests/Support/read_like_writes.php), and every write
 * route is in tests/Support/write_endpoints.php, whose entries are replayed by assertIdempotent(). A write route that
 * is not registered fails here.
 */

/** @return list<string> the write methods of $uri listed as read-like (they write nothing, so they carry no idempotency) */
function readLikeMethods(string $uri): array
{
    $methods = [];
    foreach (array_keys(readLikeWrites()) as $entry) {
        [$method, $listed] = explode(' ', $entry, 2);
        if ($listed === $uri) {
            $methods[] = $method;
        }
    }

    return $methods;
}

$writeRoutes = function (): array {
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RouteDefinition $route */
        if (! str_starts_with($route->uri(), 'api/v1') || in_array($route->uri(), publicRoutes(), true)) {
            continue;
        }
        $methods = array_diff(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']), readLikeMethods($route->uri()));
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
        foreach (array_diff(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']), readLikeMethods($route->uri())) as $method) {
            if (! in_array("{$method} {$route->uri()}", $registered, true)) {
                $unregistered[] = "{$method} {$route->uri()}";
            }
        }
    }
    expect($unregistered)->toBe([]);
})->group('invariants');

it('replays every registered write endpoint idempotently', function () {
    foreach (writeEndpoints() as $entry) {
        // setup may return route parameters (['{id}' => 7]) for uris with placeholders; the registry keeps the literal
        // route uri so the coverage check above can match it against the router.
        $params = isset($entry['setup']) ? ($entry['setup'])($this) : [];
        $uri = strtr((string) $entry['uri'], is_array($params) ? $params : []);
        assertIdempotent($this, (string) $entry['method'], $uri, ($entry['payload'])(), (string) $entry['table']);
    }
    expect(true)->toBeTrue();
})->group('invariants');

it('lists every read-like write route with a reason; each exists, is protected, and carries no idempotency', function () {
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RouteDefinition $route */
        foreach ($route->methods() as $method) {
            $routes["{$method} {$route->uri()}"] = $route;
        }
    }

    expect(readLikeWrites())->not->toBeEmpty();
    foreach (readLikeWrites() as $entry => $reason) {
        expect($reason)->toBeString()->not->toBeEmpty("{$entry}: say why it writes nothing")
            ->and($routes)->toHaveKey($entry, "{$entry} is listed as read-like but no such route exists");
        [$method, $uri] = explode(' ', $entry, 2);
        expect(in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true))->toBeTrue("{$entry}: only write methods need listing")
            ->and(in_array($uri, publicRoutes(), true))->toBeFalse("{$entry}: a read-like route is still protected");
        $middleware = $routes[$entry]->gatherMiddleware();
        expect($middleware)->toContain('auth:sanctum')
            ->and(in_array('idempotency', $middleware, true))->toBeFalse("{$entry} writes nothing, so it takes no Idempotency-Key");
    }
})->group('invariants');

it('idempotency is a registered middleware alias', function () {
    $aliases = app('router')->getMiddleware();

    expect($aliases['idempotency'] ?? null)->toBe(Idempotency::class);
})->group('invariants', 'module:shared');
