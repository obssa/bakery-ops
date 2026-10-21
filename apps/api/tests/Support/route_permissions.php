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
 * the matrix test substitutes {id}, {item}, {category}, {location}, {transfer}, {notification}, {endpoint}, {request}, {table}, {order} (an open cart of the caller's own) and {line}
 * (a queued line of a sent order of the caller's own, at a station the caller is assigned to) with a row of the right
 * table in the caller's branch, and
 * runs each module's routes in that module's Pest group (module:<module>).
 */
$all = ['admin', 'manager', 'cashier', 'waiter', 'chef', 'barista', 'cake_baker', 'bread_baker'];
$floor = ['admin', 'manager', 'cashier', 'chef', 'barista', 'cake_baker', 'bread_baker'];
$orderTakers = ['admin', 'manager', 'cashier', 'waiter'];
$stationStaff = ['admin', 'manager', 'chef', 'barista'];

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
    'PATCH /api/v1/stations/{station}' => ['permission' => 'people.manage', 'roles' => ['admin'], 'module' => 'identity'],
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
    'GET /api/v1/items/{item}/recipe/versions' => ['permission' => 'catalog.view', 'roles' => $all, 'module' => 'catalog'],
    'GET /api/v1/items/{item}/recipe/versions/{version}' => ['permission' => 'catalog.view', 'roles' => $all, 'module' => 'catalog'],
    'POST /api/v1/items/{item}/recipe/versions' => ['permission' => 'catalog.manage', 'roles' => ['admin'], 'module' => 'catalog'],
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

    // suppliers, purchases and costs (step 2.2b): purchases.record (admin, manager) records and confirms purchases; costs.view
    // (admin, manager) reads the moving averages; the opening cost is an adjustment of the books (inventory.adjust, admin)
    'GET /api/v1/suppliers' => ['permission' => 'purchases.record', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],
    'POST /api/v1/suppliers' => ['permission' => 'purchases.record', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],
    'PATCH /api/v1/suppliers/{supplier}' => ['permission' => 'purchases.record', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],
    'GET /api/v1/purchases' => ['permission' => 'purchases.record', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],
    'GET /api/v1/purchases/{purchase}' => ['permission' => 'purchases.record', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],
    'POST /api/v1/purchases' => ['permission' => 'purchases.record', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],
    'PUT /api/v1/purchases/{purchase}/lines' => ['permission' => 'purchases.record', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],
    'POST /api/v1/purchases/{purchase}/confirm' => ['permission' => 'purchases.record', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],
    'POST /api/v1/purchases/{purchase}/cancel' => ['permission' => 'purchases.record', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],

    // transfers (step 2.3): the floor reads them (inventory.view) and asks for stock (transfers.request, as the stations work);
    // only transfers.confirm (admin, manager, or anyone granted it) decides, and then only at the source's station
    'GET /api/v1/transfers' => ['permission' => 'inventory.view', 'roles' => $floor, 'module' => 'inventory'],
    'POST /api/v1/transfers' => ['permission' => 'transfers.request', 'roles' => $floor, 'module' => 'inventory'],
    'POST /api/v1/transfers/{transfer}/confirm' => ['permission' => 'transfers.confirm', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],
    'POST /api/v1/transfers/{transfer}/reject' => ['permission' => 'transfers.confirm', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],
    // reorder rules (step 2.6a): the floor reads them (inventory.view); configuring them is inventory.manage (admin), like locations
    'GET /api/v1/reorder-rules' => ['permission' => 'inventory.view', 'roles' => $floor, 'module' => 'inventory'],
    'POST /api/v1/reorder-rules' => ['permission' => 'inventory.manage', 'roles' => ['admin'], 'module' => 'inventory'],
    'PATCH /api/v1/reorder-rules/{rule}' => ['permission' => 'inventory.manage', 'roles' => ['admin'], 'module' => 'inventory'],
    'DELETE /api/v1/reorder-rules/{rule}' => ['permission' => 'inventory.manage', 'roles' => ['admin'], 'module' => 'inventory'],
    // the low-stock views (step 2.6b): the floor reads them; the strip also needs the station (403 inventory.station.forbidden, checked
    // by the action after this matrix; the matrix assigns the caller to the {station} it asks for)
    'GET /api/v1/reorder-list' => ['permission' => 'inventory.view', 'roles' => $floor, 'module' => 'inventory'],
    'GET /api/v1/stock-alerts' => ['permission' => 'inventory.view', 'roles' => $floor, 'module' => 'inventory'],
    'GET /api/v1/stations/{station}/stock-strip' => ['permission' => 'inventory.view', 'roles' => $floor, 'module' => 'inventory'],
    'GET /api/v1/display-case' => ['permission' => 'inventory.view', 'roles' => $floor, 'module' => 'inventory'],
    'GET /api/v1/inventory/costs' => ['permission' => 'costs.view', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],
    'GET /api/v1/inventory/costs/{item}' => ['permission' => 'costs.view', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],
    'POST /api/v1/inventory/costs/{item}/opening' => ['permission' => 'inventory.adjust', 'roles' => ['admin'], 'module' => 'inventory'],
    // recipe cost and margin (step 2.4b): the whole endpoint is a cost figure, so costs.view (admin, manager)
    'GET /api/v1/inventory/recipe-costs/{product}' => ['permission' => 'costs.view', 'roles' => ['admin', 'manager'], 'module' => 'inventory'],

    // Orders (step 1.3b): the order-taking roles of the section 7 matrix; on an existing order the actions also need
    // its ownership or stations.all (403 orders.order.not_owner, checked by the action, after this matrix)
    'POST /api/v1/orders' => ['permission' => 'orders.create', 'roles' => $orderTakers, 'module' => 'orders'],
    'PUT /api/v1/orders/{order}/lines' => ['permission' => 'orders.create', 'roles' => $orderTakers, 'module' => 'orders'],
    'POST /api/v1/orders/{order}/send' => ['permission' => 'orders.create', 'roles' => $orderTakers, 'module' => 'orders'],
    'POST /api/v1/orders/{order}/cancel' => ['permission' => 'orders.create', 'roles' => $orderTakers, 'module' => 'orders'],
    // void (step 1.3d): admin, manager and cashier may ask (cashier: request only); the PIN of a manager or the admin
    // approves, checked by the action after this matrix (403 identity.pin.rejected)
    'POST /api/v1/orders/{order}/void' => ['permission' => 'orders.void_request', 'roles' => ['admin', 'manager', 'cashier'], 'module' => 'orders'],
    // station actions (step 1.3c): accept / ready / reject need orders.accept (chef, barista; admin and manager hold
    // stations.all); the line's own station is checked by the action after this matrix (403 orders.station.forbidden).
    // Served is the waiter's: orders.create, on an order of their own or with stations.all (403 orders.order.not_owner)
    'POST /api/v1/order-lines/{line}/accept' => ['permission' => 'orders.accept', 'roles' => $stationStaff, 'module' => 'orders'],
    'POST /api/v1/order-lines/{line}/ready' => ['permission' => 'orders.accept', 'roles' => $stationStaff, 'module' => 'orders'],
    'POST /api/v1/order-lines/{line}/reject' => ['permission' => 'orders.accept', 'roles' => $stationStaff, 'module' => 'orders'],
    'POST /api/v1/order-lines/{line}/served' => ['permission' => 'orders.create', 'roles' => $orderTakers, 'module' => 'orders'],

    // read models (step 1.5): the order takers read the menu, their order list and one order (a waiter only their own,
    // 404 otherwise; checked by the endpoint after this matrix); the station rail needs orders.accept, and at that
    // station (403 orders.station.forbidden; admin and manager hold stations.all)
    'GET /api/v1/menu' => ['permission' => 'orders.create', 'roles' => $orderTakers, 'module' => 'orders'],
    'GET /api/v1/orders' => ['permission' => 'orders.create', 'roles' => $orderTakers, 'module' => 'orders'],
    'GET /api/v1/orders/{order}' => ['permission' => 'orders.create', 'roles' => $orderTakers, 'module' => 'orders'],
    'GET /api/v1/stations/{station}/tickets' => ['permission' => 'orders.accept', 'roles' => $stationStaff, 'module' => 'orders'],

    // dining tables (step 1.3e): the order takers read the grid, only the admin (tables.manage) configures it
    'GET /api/v1/dining-tables' => ['permission' => 'orders.create', 'roles' => $orderTakers, 'module' => 'orders'],
    'POST /api/v1/dining-tables' => ['permission' => 'tables.manage', 'roles' => ['admin'], 'module' => 'orders'],
    'PATCH /api/v1/dining-tables/{table}' => ['permission' => 'tables.manage', 'roles' => ['admin'], 'module' => 'orders'],

    // production requests (step 2.7b): the floor reads the board and a card; production.request asks, production.plan assigns,
    // production.fulfil accepts (and at the request's station: 403 production.station.forbidden, checked by the action);
    // cancel is the planner's or the requester's own, so the route asks only for production.view and the action decides
    // (403 production.cancel.forbidden)
    'GET /api/v1/production/requests' => ['permission' => 'production.view', 'roles' => $floor, 'module' => 'production'],
    'GET /api/v1/production/requests/{request}' => ['permission' => 'production.view', 'roles' => $floor, 'module' => 'production'],
    'POST /api/v1/production/requests' => ['permission' => 'production.request', 'roles' => ['admin', 'manager', 'cashier', 'chef', 'barista'], 'module' => 'production'],
    'POST /api/v1/production/requests/{request}/assign' => ['permission' => 'production.plan', 'roles' => ['admin', 'manager'], 'module' => 'production'],
    'POST /api/v1/production/requests/{request}/accept' => ['permission' => 'production.fulfil', 'roles' => ['admin', 'cake_baker', 'bread_baker'], 'module' => 'production'],
    'POST /api/v1/production/requests/{request}/cancel' => ['permission' => 'production.view', 'roles' => $floor, 'module' => 'production'],
    // production batches (step 2.7c): start and done are the assignee's (production.fulfil; the action also checks the station and the
    // assignee); confirm and send-back are the overseer's or a production.confirm holder's, which depends on the station, so the
    // route asks only for production.view and the action decides (403 production.confirm.forbidden)
    'POST /api/v1/production/requests/{request}/start' => ['permission' => 'production.fulfil', 'roles' => ['admin', 'cake_baker', 'bread_baker'], 'module' => 'production'],
    'POST /api/v1/production/batches/{batch}/done' => ['permission' => 'production.fulfil', 'roles' => ['admin', 'cake_baker', 'bread_baker'], 'module' => 'production'],
    'POST /api/v1/production/batches/{batch}/confirm' => ['permission' => 'production.view', 'roles' => $floor, 'module' => 'production'],
    'POST /api/v1/production/batches/{batch}/send-back' => ['permission' => 'production.view', 'roles' => $floor, 'module' => 'production'],

    // Notifications (step 2.5a): every role has its own inbox (notifications.view); the lines are always the caller's own
    'GET /api/v1/notifications' => ['permission' => 'notifications.view', 'roles' => $all, 'module' => 'notifications'],
    'POST /api/v1/notifications/{notification}/read' => ['permission' => 'notifications.view', 'roles' => $all, 'module' => 'notifications'],
    'POST /api/v1/notifications/read-all' => ['permission' => 'notifications.view', 'roles' => $all, 'module' => 'notifications'],
    // Notifications (step 2.5b): the caller's own channels and endpoints (push subscription, Telegram link); the Telegram webhook is public
    // (tests/Support/public_routes.php) and guarded by its secret, so it is not here
    'GET /api/v1/notifications/channels' => ['permission' => 'notifications.view', 'roles' => $all, 'module' => 'notifications'],
    'POST /api/v1/notifications/endpoints' => ['permission' => 'notifications.view', 'roles' => $all, 'module' => 'notifications'],
    'DELETE /api/v1/notifications/endpoints/{endpoint}' => ['permission' => 'notifications.view', 'roles' => $all, 'module' => 'notifications'],
    'POST /api/v1/notifications/telegram/link' => ['permission' => 'notifications.view', 'roles' => $all, 'module' => 'notifications'],

    // Payments (step 1.4): the till's roles (payments.take: admin, manager, cashier); the person acting by short code must
    // hold it too (403 auth.forbidden, checked by the action after this matrix)
    'GET /api/v1/payment-channels' => ['permission' => 'payments.take', 'roles' => ['admin', 'manager', 'cashier'], 'module' => 'payments'],
    'POST /api/v1/orders/{order}/payments' => ['permission' => 'payments.take', 'roles' => ['admin', 'manager', 'cashier'], 'module' => 'payments'],
];
