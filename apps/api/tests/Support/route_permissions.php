<?php

declare(strict_types=1);

/*
 * Registry route -> permission -> roles allowed by default (rule 6; architecture.md section 7 matrix).
 * tests/Invariants/RoutePermissionCoverageTest.php fails when an api/v1 route is neither public nor listed here;
 * tests/Feature/RoutePermissionMatrixTest.php hits every entry as every role expecting 403 or not.
 *
 *   'GET /api/v1/items' => ['permission' => 'catalog.view', 'roles' => ['admin', 'manager', 'cashier', ...], 'module' => 'catalog'],
 *
 * `identity.authenticated` is the gate ability every active user holds (GET /me, POST /logout): it is a permission
 * check in the middleware sense (can:) without being a matrix permission. Uris keep the literal route placeholders;
 * the matrix test substitutes {id}, {item}, {category} and {location} with a row of the right table in the caller's branch, and
 * runs each module's routes in that module's Pest group (module:<module>).
 */
$all = ['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'];
$floor = ['admin', 'manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker'];

return [
    // Identity (step 0.4)
    'GET /api/v1/me' => ['permission' => 'identity.authenticated', 'roles' => $all, 'module' => 'identity'],
    'POST /api/v1/logout' => ['permission' => 'identity.authenticated', 'roles' => $all, 'module' => 'identity'],
    'GET /api/v1/users' => ['permission' => 'people.manage', 'roles' => ['admin'], 'module' => 'identity'],
    'POST /api/v1/users' => ['permission' => 'people.manage', 'roles' => ['admin'], 'module' => 'identity'],
    'PATCH /api/v1/users/{id}' => ['permission' => 'people.manage', 'roles' => ['admin'], 'module' => 'identity'],
    'POST /api/v1/users/{id}/short-code/reset' => ['permission' => 'people.manage', 'roles' => ['admin'], 'module' => 'identity'],
    'PUT /api/v1/users/{id}/stations' => ['permission' => 'people.manage', 'roles' => ['admin'], 'module' => 'identity'],
    'GET /api/v1/stations' => ['permission' => 'people.manage', 'roles' => ['admin'], 'module' => 'identity'],
    'POST /api/v1/stations' => ['permission' => 'people.manage', 'roles' => ['admin'], 'module' => 'identity'],
    'GET /api/v1/devices' => ['permission' => 'people.manage', 'roles' => ['admin'], 'module' => 'identity'],
    'POST /api/v1/devices' => ['permission' => 'people.manage', 'roles' => ['admin'], 'module' => 'identity'],
    'POST /api/v1/devices/{id}/revoke' => ['permission' => 'people.manage', 'roles' => ['admin'], 'module' => 'identity'],
    // live updates (step 1.2, ADR 0009): every active user may ask; the channel rules decide which channel (403 otherwise)
    'POST /api/v1/broadcasting/auth' => ['permission' => 'identity.authenticated', 'roles' => $all, 'module' => 'identity'],

    // Catalog (step 0.5): every role reads the catalog, only admin edits items, recipes and prices (section 7 matrix)
    'GET /api/v1/units' => ['permission' => 'catalog.view', 'roles' => $all, 'module' => 'catalog'],
    'POST /api/v1/units' => ['permission' => 'catalog.manage', 'roles' => ['admin'], 'module' => 'catalog'],
    'GET /api/v1/categories' => ['permission' => 'catalog.view', 'roles' => $all, 'module' => 'catalog'],
    'POST /api/v1/categories' => ['permission' => 'catalog.manage', 'roles' => ['admin'], 'module' => 'catalog'],
    'PATCH /api/v1/categories/{category}' => ['permission' => 'catalog.manage', 'roles' => ['admin'], 'module' => 'catalog'],
    'DELETE /api/v1/categories/{category}' => ['permission' => 'catalog.manage', 'roles' => ['admin'], 'module' => 'catalog'],
    'GET /api/v1/items' => ['permission' => 'catalog.view', 'roles' => $all, 'module' => 'catalog'],
    'POST /api/v1/items' => ['permission' => 'catalog.manage', 'roles' => ['admin'], 'module' => 'catalog'],
    'GET /api/v1/items/{item}' => ['permission' => 'catalog.view', 'roles' => $all, 'module' => 'catalog'],
    'PATCH /api/v1/items/{item}' => ['permission' => 'catalog.manage', 'roles' => ['admin'], 'module' => 'catalog'],
    'DELETE /api/v1/items/{item}' => ['permission' => 'catalog.manage', 'roles' => ['admin'], 'module' => 'catalog'],
    'GET /api/v1/items/{item}/prices' => ['permission' => 'catalog.view', 'roles' => $all, 'module' => 'catalog'],
    'POST /api/v1/items/{item}/prices' => ['permission' => 'catalog.manage', 'roles' => ['admin'], 'module' => 'catalog'],
    'GET /api/v1/items/{item}/recipe' => ['permission' => 'catalog.view', 'roles' => $all, 'module' => 'catalog'],
    'PUT /api/v1/items/{item}/recipe' => ['permission' => 'catalog.manage', 'roles' => ['admin'], 'module' => 'catalog'],
    'PUT /api/v1/items/{item}/stations' => ['permission' => 'catalog.manage', 'roles' => ['admin'], 'module' => 'catalog'],
    'GET /api/v1/items/{item}/modifiers' => ['permission' => 'catalog.view', 'roles' => $all, 'module' => 'catalog'],
    'POST /api/v1/items/{item}/modifiers' => ['permission' => 'catalog.manage', 'roles' => ['admin'], 'module' => 'catalog'],

    // Inventory (step 0.6): the floor reads stock and the ledger (not the waiter); admin configures locations and
    // records the Phase 0 manual movements
    'GET /api/v1/locations' => ['permission' => 'inventory.view', 'roles' => $floor, 'module' => 'inventory'],
    'POST /api/v1/locations' => ['permission' => 'inventory.manage', 'roles' => ['admin'], 'module' => 'inventory'],
    'PATCH /api/v1/locations/{location}' => ['permission' => 'inventory.manage', 'roles' => ['admin'], 'module' => 'inventory'],
    'GET /api/v1/stock' => ['permission' => 'inventory.view', 'roles' => $floor, 'module' => 'inventory'],
    'POST /api/v1/stock/movements' => ['permission' => 'inventory.adjust', 'roles' => ['admin'], 'module' => 'inventory'],
    'GET /api/v1/ledger' => ['permission' => 'inventory.view', 'roles' => $floor, 'module' => 'inventory'],
];
