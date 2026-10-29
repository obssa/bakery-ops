<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Middleware\EnsureEnrolledDevice;
use App\Modules\Identity\Http\Middleware\ResolveActor;
use App\Shared\Http\Middleware\Idempotency;
use App\Shared\Models\Branch;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Middleware\PermissionMiddleware;

/*
 * Step 5.1a (phase-5 plan 4.1, ADR 0017): every api/v1 route is either a station route (tests/Support/station_routes.php: the routes a
 * shared tablet calls, which carry the `device` middleware) or not (tests/Support/non_station_routes.php), never both and never neither,
 * so a new route cannot ship unclassified. With identity.require_enrolled_device on, every station route refuses a device nobody enrolled
 * (403 identity.device.unknown, even for the admin, before the permission check), and no other route gives a 403 from the device check
 * (sent as a waiter, whose missing permissions would be answered by the permission check, after the device check). Each module's routes
 * run in that module's Pest group, as the permission matrix's do.
 */

$stationRoutes = require __DIR__.'/../Support/station_routes.php';
$nonStationRoutes = require __DIR__.'/../Support/non_station_routes.php';

/**
 * Every api/v1 route by 'METHOD /uri' (HEAD and OPTIONS left out).
 *
 * @return array<string, RouteDefinition>
 */
function stationRegistryRoutes(): array
{
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RouteDefinition $route */
        if (! str_starts_with($route->uri(), 'api/v1')) {
            continue;
        }
        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $routes["{$method} /{$route->uri()}"] = $route;
        }
    }

    return $routes;
}

it('classifies every api/v1 route as a station route or not, exactly once', function () use ($stationRoutes, $nonStationRoutes) {
    $routes = array_keys(stationRegistryRoutes());
    $station = array_keys($stationRoutes);
    $other = array_keys($nonStationRoutes);

    expect(array_values(array_intersect($station, $other)))->toBe([], 'in both lists')
        ->and(array_values(array_diff($routes, $station, $other)))->toBe([], 'in neither list: classify it in tests/Support/station_routes.php or non_station_routes.php')
        ->and(array_values(array_diff([...$station, ...$other], $routes)))->toBe([], 'listed, but no such route');
    foreach ($stationRoutes as $key => $spec) {
        expect($spec['module'])->toBeString()->not->toBeEmpty($key)
            ->and($spec['why'])->toBeString()->not->toBeEmpty($key);
    }
})->group('feature', 'invariants', 'module:identity');

it('puts the device middleware on every station route and on no other route', function () use ($stationRoutes) {
    $problems = [];
    foreach (stationRegistryRoutes() as $key => $route) {
        $has = in_array('device', $route->gatherMiddleware(), true);
        if (isset($stationRoutes[$key]) && ! $has) {
            $problems[] = "{$key}: a station route without the device middleware";
        }
        if (! isset($stationRoutes[$key]) && $has) {
            $problems[] = "{$key}: the device middleware on a route that is not a station route";
        }
    }

    expect($problems)->toBe([]);
})->group('feature', 'invariants', 'module:identity');

it('runs the device check after the actor and before the permission and the idempotency key', function () use ($stationRoutes) {
    $routes = stationRegistryRoutes();
    $problems = [];
    foreach (array_keys($stationRoutes) as $key) {
        $stack = array_values(Route::gatherRouteMiddleware($routes[$key]));
        $at = function (string $class) use ($stack): ?int {
            foreach ($stack as $i => $middleware) {
                if (is_string($middleware) && ($middleware === $class || str_starts_with($middleware, $class.':'))) {
                    return $i;
                }
            }

            return null;
        };
        $device = $at(EnsureEnrolledDevice::class);
        if ($device === null) {
            $problems[] = "{$key}: no EnsureEnrolledDevice";

            continue;
        }
        foreach ([ResolveActor::class => 'before', PermissionMiddleware::class => 'after', Idempotency::class => 'after'] as $class => $where) {
            $other = $at($class);
            if ($other !== null && ($where === 'before' ? $other > $device : $other < $device)) {
                $problems[] = "{$key}: ".class_basename($class)." should run {$where} the device check";
            }
        }
    }

    expect($problems)->toBe([]);
})->group('feature', 'invariants', 'module:identity');

$deviceCases = [];
foreach ($stationRoutes as $key => $spec) {
    $deviceCases[(string) $spec['module']]["{$key} (station)"] = [$key, true];
}
foreach ($nonStationRoutes as $key => $module) {
    $deviceCases[(string) $module][$key] = [$key, false];
}

foreach ($deviceCases as $module => $cases) {
    it("refuses an unknown device on every station route of {$module} and on no other, with the flag on", function (string $key, bool $isStation) {
        seedRoles();
        config(['identity.require_enrolled_device' => true]);
        $branch = Branch::factory()->create();
        loginAs(makeUser($branch, $isStation ? 'admin' : 'waiter'));

        [$method, $uri] = explode(' ', $key, 2);
        $uri = (string) preg_replace('/\{[a-z_]+\}/', '1', $uri);
        $response = $this->withHeaders(idempotencyKey() + [ResolveActor::DEVICE_HEADER => 'never-enrolled-'.Str::uuid()])->json($method, $uri, []);

        if ($isStation) {
            $response->assertStatus(403)->assertJsonPath('message_key', 'identity.device.unknown');
        } else {
            expect((string) $response->json('message_key'))->not->toStartWith('identity.device.', "{$key} answered the device check: ".$response->getContent());
        }
    })->with($cases)->group('feature', "module:{$module}");
}
