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
 * the matrix test substitutes {id} with a row of the right table in the caller's branch.
 */
$all = ['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'];

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
];
