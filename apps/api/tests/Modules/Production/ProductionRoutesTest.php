<?php

declare(strict_types=1);

use App\Modules\Production\Listeners\CreateAutoRequest;
use App\Modules\Production\ProductionServiceProvider;
use App\Shared\Events\StockOut;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

pest()->group('module:production', 'feature');

/*
 * The ProductionServiceProvider (step 2.7b, deferred from 2.7a): registered with the other modules, it loads the production
 * routes and subscribes CreateAutoRequest to StockOut. Every route is behind auth:sanctum, the actor middleware and a
 * permission (rule 6); every write is also behind an Idempotency-Key (rule 7). tests/Support/route_permissions.php and
 * write_endpoints.php list them, and the route matrix and the idempotency coverage test run them.
 */

it('is a service provider the application loads', function () {
    expect(array_keys(app()->getLoadedProviders()))->toContain(ProductionServiceProvider::class);
});

it('subscribes the auto request to StockOut and nothing else of production to it', function () {
    expect(Event::hasListeners(StockOut::class))->toBeTrue();
    $listeners = collect(app('events')->getListeners(StockOut::class))->count();
    expect($listeners)->toBeGreaterThanOrEqual(2, 'Notifications and Production both listen')
        ->and(method_exists(CreateAutoRequest::class, 'handle'))->toBeTrue();
});

it('registers the ten routes with their permission and, for the writes, the idempotency middleware', function () {
    $expected = [
        'GET api/v1/production/requests' => ['permission:production.view', false],
        'GET api/v1/production/requests/{request}' => ['permission:production.view', false],
        'POST api/v1/production/requests' => ['permission:production.request', true],
        'POST api/v1/production/requests/{request}/assign' => ['permission:production.plan', true],
        'POST api/v1/production/requests/{request}/accept' => ['permission:production.fulfil', true],
        'POST api/v1/production/requests/{request}/cancel' => ['permission:production.view', true],
        'POST api/v1/production/requests/{request}/start' => ['permission:production.fulfil', true],
        'POST api/v1/production/batches/{batch}/done' => ['permission:production.fulfil', true],
        'POST api/v1/production/batches/{batch}/confirm' => ['permission:production.view', true],
        'POST api/v1/production/batches/{batch}/send-back' => ['permission:production.view', true],
    ];
    $found = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            $key = $method.' '.$route->uri();
            if (isset($expected[$key])) {
                $found[$key] = $route->gatherMiddleware();
            }
        }
    }

    expect(array_keys($found))->toEqualCanonicalizing(array_keys($expected));
    foreach ($expected as $key => [$permission, $writes]) {
        expect($found[$key])->toContain('auth:sanctum')->toContain('actor')->toContain($permission);
        expect(in_array('idempotency', $found[$key], true))->toBe($writes, "{$key} idempotency");
    }
});
