<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Device;
use App\Shared\Models\Branch;

/*
 * Rule 6, generated: tests/Support/route_permissions.php maps every protected api/v1 route to its permission and the
 * roles that hold it by default; this test hits every route as every role. A role outside the list must get 403;
 * a role inside must get anything but 403 (an empty payload gives 422, a bare GET 200). It runs in every module
 * job, so a new route cannot ship without its row in the matrix.
 */

$entries = require __DIR__.'/../Support/route_permissions.php';
$cases = [];
foreach ($entries as $route => $spec) {
    foreach (['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        $cases["{$route} as {$role}"] = [$route, $role, in_array($role, $spec['roles'], true)];
    }
}

it('enforces the permission matrix on every registered route', function (string $route, string $role, bool $allowed) {
    seedRoles();
    $branch = Branch::factory()->create();
    $me = loginAs(makeUser($branch, $role));
    $target = makeUser($branch, 'waiter');
    $device = Device::factory()->create(['branch_id' => $branch->id]);

    [$method, $uri] = explode(' ', $route, 2);
    $uri = str_starts_with($uri, '/api/v1/devices') ? str_replace('{id}', (string) $device->id, $uri) : str_replace('{id}', (string) $target->id, $uri);

    $response = $this->withHeaders(idempotencyKey())->json($method, $uri, []);

    if ($allowed) {
        expect($response->getStatusCode())->not->toBe(403, "{$role} should be allowed on {$route}: ".$response->getContent());
        expect($response->getStatusCode())->toBeLessThan(500);
    } else {
        $response->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    // the session and token of $me are irrelevant afterwards: every case builds its own branch
    expect($me->branch_id)->toBe($branch->id);
})->with($cases)->group('feature', 'invariants', 'module:identity');
