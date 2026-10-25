<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
 * Rule 6 at the route level: every api/v1 route is behind auth:sanctum and a permission/can middleware unless it
 * is public, and every protected route is in tests/Support/route_permissions.php so the role matrix test
 * (step 0.4 onward) can hit it as every role. A stray route fails here.
 */

$protectedRoutes = function (): array {
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RouteDefinition $route */
        if (str_starts_with($route->uri(), 'api/v1') && ! isPublicRoute($route)) {
            $routes[] = $route;
        }
    }

    return $routes;
};

it('every non-public api/v1 route requires a bearer token and a permission', function () use ($protectedRoutes) {
    $problems = [];
    foreach ($protectedRoutes() as $route) {
        $middleware = implode(' ', $route->gatherMiddleware());
        $label = implode('|', $route->methods()).' '.$route->uri();
        if (! str_contains($middleware, 'auth:sanctum')) {
            $problems[] = "{$label}: no auth:sanctum";
        }
        if (preg_match('/\b(permission|can|role_or_permission):/', $middleware) !== 1) {
            $problems[] = "{$label}: no permission:/can: middleware";
        }
    }
    expect($problems)->toBe([]);
})->group('invariants');

it('every non-public api/v1 route is registered in tests/Support/route_permissions.php', function () use ($protectedRoutes) {
    $registered = array_keys(routePermissions());
    $unregistered = [];
    foreach ($protectedRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            if (! in_array("{$method} /{$route->uri()}", $registered, true)) {
                $unregistered[] = "{$method} /{$route->uri()}";
            }
        }
    }
    expect($unregistered)->toBe([]);
})->group('invariants');

it('serves the public routes without authentication', function () {
    foreach (publicRoutes() as $uri) {
        expect(ltrim(substr(strstr($uri, ' ') ?: ' '.$uri, 1)))->toStartWith('api/v1/');
    }
    $this->getJson('/api/v1/health')->assertOk();
})->group('invariants');
