<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PermissionRegistry;

/*
 * The section 7 matrix as data (workflows-and-screens.md): eight roles, dotted permission names, admin holds
 * everything. Plain PHP, no container: the registry is what RolesSeeder and the route registry both read.
 */

it('names the eight roles of the matrix', function () {
    expect(PermissionRegistry::ROLES)->toBe(['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker']);
})->group('unit', 'module:identity');

it('uses dotted lowercase permission names and lists every permission exactly once', function () {
    $all = PermissionRegistry::permissions();

    expect($all)->not->toBeEmpty()->and($all)->toBe(array_values(array_unique($all)));
    foreach ($all as $permission) {
        expect($permission)->toMatch('/^[a-z]+(\.[a-z_]+)+$/');
    }
    expect($all)->toContain(
        'catalog.manage', 'catalog.view', 'people.manage', 'inventory.view', 'inventory.adjust', 'inventory.manage',
        'orders.create', 'orders.accept', 'orders.void', 'payments.take', 'payments.void',
        'production.request', 'production.fulfil', 'production.confirm', 'transfers.request', 'transfers.confirm',
        'shifts.count', 'shifts.approve', 'shifts.unlock', 'channels.manage', 'analytics.view', 'audit.view', 'tables.manage',
    );
})->group('unit', 'module:identity');

it('gives admin every permission', function () {
    expect(PermissionRegistry::permissionsFor('admin'))->toEqualCanonicalizing(PermissionRegistry::permissions());
})->group('unit', 'module:identity');

it('follows the matrix row by row', function (string $permission, array $roles) {
    expect(PermissionRegistry::rolesFor($permission))->toEqualCanonicalizing($roles);
    foreach (PermissionRegistry::ROLES as $role) {
        expect(in_array($permission, PermissionRegistry::permissionsFor($role), true))->toBe(in_array($role, $roles, true), "{$role} / {$permission}");
    }
})->with([
    'create order' => ['orders.create', ['admin', 'manager', 'cashier', 'waiter']],
    'accept / ready a line' => ['orders.accept', ['admin', 'manager', 'chef', 'barista']],
    'take payment' => ['payments.take', ['admin', 'manager', 'cashier']],
    'void / refund' => ['orders.void', ['admin', 'manager']],
    'void: request only' => ['orders.void_request', ['admin', 'manager', 'cashier']],
    'refund payment' => ['payments.void', ['admin', 'manager']],
    'create production request' => ['production.request', ['admin', 'manager', 'cashier', 'chef', 'barista']],
    'confirm production request' => ['production.confirm', ['admin', 'manager']],
    'fulfil production request' => ['production.fulfil', ['admin', 'cake_baker', 'bread_baker']],
    'record purchase' => ['purchases.record', ['admin', 'manager']],
    'see stock and the ledger' => ['inventory.view', ['admin', 'manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker']],
    'adjust stock by hand' => ['inventory.adjust', ['admin']],
    'configure stock locations' => ['inventory.manage', ['admin']],
    'request transfer' => ['transfers.request', ['admin', 'manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker']],
    'confirm transfer' => ['transfers.confirm', ['admin', 'manager']],
    'enter counts' => ['shifts.count', ['admin', 'manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker']],
    'approve variance' => ['shifts.approve', ['admin', 'manager']],
    'unlock a locked count' => ['shifts.unlock', ['admin']],
    'edit items, recipes, prices' => ['catalog.manage', ['admin']],
    'see the catalog' => ['catalog.view', ['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker']],
    'manage payment channels' => ['channels.manage', ['admin']],
    'manage users' => ['people.manage', ['admin']],
    'view analytics' => ['analytics.view', ['admin', 'manager']],
    'view own figures' => ['analytics.view_own', ['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker']],
    'view audit log' => ['audit.view', ['admin', 'manager']],
    'act at any station' => ['stations.all', ['admin', 'manager']],
    'manage the dining tables (Phase 1)' => ['tables.manage', ['admin']],
])->group('unit', 'module:identity');

it('knows no role outside the eight and no permission outside the matrix', function () {
    expect(PermissionRegistry::permissionsFor('ceo'))->toBe([])
        ->and(PermissionRegistry::rolesFor('world.domination'))->toBe([])
        ->and(PermissionRegistry::isPermission('orders.create'))->toBeTrue()
        ->and(PermissionRegistry::isPermission('orders.destroy'))->toBeFalse()
        ->and(PermissionRegistry::isRole('barista'))->toBeTrue()
        ->and(PermissionRegistry::isRole('overseer'))->toBeFalse();
})->group('unit', 'module:identity');
