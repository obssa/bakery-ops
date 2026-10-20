<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Inventory\Models\Location;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;

/*
 * Rule 6, generated: tests/Support/route_permissions.php maps every protected api/v1 route to its permission and the
 * roles that hold it by default; this test hits every route as every role. A role outside the list must get 403;
 * a role inside must get anything but 403 (an empty payload gives 422, a bare GET 200). Each module's routes run in
 * that module's Pest group (module:<name>), so a module job proves its own routes and a new route cannot ship
 * without its row in the matrix.
 */

$entries = require __DIR__.'/../Support/route_permissions.php';
$casesByModule = [];
foreach ($entries as $route => $spec) {
    foreach (['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        $casesByModule[(string) $spec['module']]["{$route} as {$role}"] = [$route, $role, in_array($role, $spec['roles'], true)];
    }
}

/** Placeholder values for one case: rows of the right table in the caller's branch, created only when the uri needs them. */
function matrixParameters(string $uri, Branch $branch, int $actorId): array
{
    $params = [];
    if (str_contains($uri, '{id}')) {
        $params['{id}'] = str_starts_with($uri, '/api/v1/devices')
            ? (string) Device::factory()->create(['branch_id' => $branch->id])->id
            : (string) makeUser($branch, 'waiter')->id;
    }
    if (str_contains($uri, '{item}')) {
        $params['{item}'] = (string) Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{category}')) {
        $params['{category}'] = (string) Category::factory()->create(['branch_id' => $branch->id])->id;
    }
    if (str_contains($uri, '{location}')) {
        $params['{location}'] = (string) Location::factory()->create(['branch_id' => $branch->id])->id;
    }
    if (str_contains($uri, '{table}')) {
        $params['{table}'] = (string) DiningTable::factory()->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{order}')) {
        // an open cart of the caller's own (an order of someone else is refused by the action, not by the matrix)
        $params['{order}'] = (string) Order::factory()->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId])->id;
    }
    if (str_contains($uri, '{line}')) {
        // a queued line of a sent order of the caller's own, at a station the caller is assigned to (another station is
        // refused by the action, not by the matrix: orders.station.forbidden)
        $station = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $branch->id]);
        UserStationAssignment::factory()->create(['branch_id' => $branch->id, 'user_id' => $actorId, 'station_id' => $station->id]);
        $order = Order::factory()->sent()->create(['branch_id' => $branch->id, 'created_by_user_id' => $actorId]);
        $params['{line}'] = (string) OrderLine::factory()->atStation($station)->create(['order_id' => $order->id])->id;
    }

    return $params;
}

foreach ($casesByModule as $module => $cases) {
    it("enforces the permission matrix on every registered {$module} route", function (string $route, string $role, bool $allowed) {
        seedRoles();
        $branch = Branch::factory()->create();
        $me = loginAs(makeUser($branch, $role));

        [$method, $uri] = explode(' ', $route, 2);
        $uri = strtr($uri, matrixParameters($uri, $branch, $me->id));

        $response = $this->withHeaders(idempotencyKey())->json($method, $uri, []);

        if ($allowed) {
            expect($response->getStatusCode())->not->toBe(403, "{$role} should be allowed on {$route}: ".$response->getContent());
            expect($response->getStatusCode())->toBeLessThan(500);
        } else {
            $response->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
        }
        // the session and token of $me are irrelevant afterwards: every case builds its own branch
        expect($me->branch_id)->toBe($branch->id);
    })->with($cases)->group('feature', 'invariants', "module:{$module}");
}
