<?php

declare(strict_types=1);

/*
 * Station routes (step 5.1a, phase-5 plan 4.1, ADR 0017): the api/v1 routes a shared tablet calls to do floor work: the pad, the KDS,
 * the till, the production board, the shift bar, the count and cash screens, and the pad's lock screen. Each carries the `device`
 * middleware (Identity's EnsureEnrolledDevice) right after `actor`; once identity.require_enrolled_device is on, they answer only an
 * enrolled, unrevoked tablet of the caller's branch (403 identity.device.unknown | revoked | other_branch). Every other route is in
 * tests/Support/non_station_routes.php; tests/Feature/StationRoutesTest.php fails when a route is in neither list, in both, or when a
 * route's middleware disagrees with its list, so a new route must be classified when it is added.
 *
 * A route the back office also reads (the order list, one order, a shift, a count, the reasons) is a station route when a floor screen
 * needs it: once the flag is on, a computer that opens those admin pages is enrolled like a tablet (ADR 0017).
 *
 *   'METHOD /api/v1/uri' => ['module' => '<the module the route belongs to>', 'why' => '<the screen that calls it>'],
 */
return [
    // identity
    'POST /api/v1/short-code/verify' => ['module' => 'identity', 'why' => 'the pad lock screen: a short code starts the acting-as session'],
    // inventory
    'GET /api/v1/display-case' => ['module' => 'inventory', 'why' => 'till: the display case'],
    'GET /api/v1/stations/{station}/stock-strip' => ['module' => 'inventory', 'why' => 'KDS: the station stock strip'],
    'POST /api/v1/transfers' => ['module' => 'inventory', 'why' => 'KDS strip: ask the store for stock'],
    // orders
    'GET /api/v1/dining-tables' => ['module' => 'orders', 'why' => 'pad: the table grid'],
    'GET /api/v1/menu' => ['module' => 'orders', 'why' => 'pad: the menu'],
    'POST /api/v1/order-lines/{line}/accept' => ['module' => 'orders', 'why' => 'KDS: accept'],
    'POST /api/v1/order-lines/{line}/ready' => ['module' => 'orders', 'why' => 'KDS: ready'],
    'POST /api/v1/order-lines/{line}/reject' => ['module' => 'orders', 'why' => 'KDS: reject'],
    'POST /api/v1/order-lines/{line}/served' => ['module' => 'orders', 'why' => 'pad: serve a ready line'],
    'GET /api/v1/orders' => ['module' => 'orders', 'why' => 'pad and till: the order list'],
    'POST /api/v1/orders' => ['module' => 'orders', 'why' => 'pad: a new order'],
    'GET /api/v1/orders/{order}' => ['module' => 'orders', 'why' => 'pad and till: one order'],
    'POST /api/v1/orders/{order}/cancel' => ['module' => 'orders', 'why' => 'pad: cancel'],
    'PUT /api/v1/orders/{order}/lines' => ['module' => 'orders', 'why' => 'pad: replace lines'],
    'POST /api/v1/orders/{order}/send' => ['module' => 'orders', 'why' => 'pad: send'],
    'POST /api/v1/orders/{order}/void' => ['module' => 'orders', 'why' => 'till: ask for a void (a PIN approves)'],
    'GET /api/v1/stations/{station}/tickets' => ['module' => 'orders', 'why' => 'KDS: the station rail'],
    // payments
    'POST /api/v1/orders/{order}/payment-batches' => ['module' => 'payments', 'why' => 'till: a split bill'],
    'GET /api/v1/orders/{order}/payments' => ['module' => 'payments', 'why' => 'till: the payments of an order'],
    'POST /api/v1/orders/{order}/payments' => ['module' => 'payments', 'why' => 'till: a payment'],
    'POST /api/v1/orders/{order}/receipt-link' => ['module' => 'payments', 'why' => 'till: a receipt link'],
    'GET /api/v1/orders/{order}/receipt.pdf' => ['module' => 'payments', 'why' => 'till: the receipt'],
    'POST /api/v1/orders/{order}/refund' => ['module' => 'payments', 'why' => 'till: a refund (a PIN approves)'],
    'GET /api/v1/payment-channels' => ['module' => 'payments', 'why' => 'till: the channels it takes'],
    'POST /api/v1/payments/{payment}/void' => ['module' => 'payments', 'why' => 'till: ask for a payment void (a PIN approves)'],
    'GET /api/v1/receipt-settings' => ['module' => 'payments', 'why' => 'till: what a receipt prints'],
    // production
    'POST /api/v1/production/batches/{batch}/confirm' => ['module' => 'production', 'why' => 'requester or overseer: confirm'],
    'POST /api/v1/production/batches/{batch}/done' => ['module' => 'production', 'why' => 'baker: done'],
    'POST /api/v1/production/batches/{batch}/send-back' => ['module' => 'production', 'why' => 'requester or overseer: send back'],
    'GET /api/v1/production/requests' => ['module' => 'production', 'why' => 'production board'],
    'POST /api/v1/production/requests' => ['module' => 'production', 'why' => 'pad, till or station: ask for a bake'],
    'GET /api/v1/production/requests/{request}' => ['module' => 'production', 'why' => 'production board: one request'],
    'POST /api/v1/production/requests/{request}/accept' => ['module' => 'production', 'why' => 'baker: accept'],
    'POST /api/v1/production/requests/{request}/cancel' => ['module' => 'production', 'why' => 'requester: cancel'],
    'POST /api/v1/production/requests/{request}/start' => ['module' => 'production', 'why' => 'baker: start'],
    // shifts
    'GET /api/v1/shifts/{shift}' => ['module' => 'shifts', 'why' => 'shift bar: the shift'],
    'POST /api/v1/shifts/{shift}/cancel' => ['module' => 'shifts', 'why' => 'shift bar: cancel an opening shift'],
    'GET /api/v1/shifts/{shift}/cash' => ['module' => 'shifts', 'why' => 'till: the cash widget'],
    'POST /api/v1/shifts/{shift}/close' => ['module' => 'shifts', 'why' => 'shift bar: close'],
    'GET /api/v1/shifts/{shift}/counts' => ['module' => 'shifts', 'why' => 'count screen: the counts of the shift'],
    'POST /api/v1/shifts/{shift}/join' => ['module' => 'shifts', 'why' => 'shift bar: join'],
    'POST /api/v1/shifts/{shift}/leave' => ['module' => 'shifts', 'why' => 'shift bar: leave'],
    'GET /api/v1/shifts/{shift}/notes' => ['module' => 'shifts', 'why' => 'shift bar: notes'],
    'POST /api/v1/shifts/{shift}/notes' => ['module' => 'shifts', 'why' => 'shift bar: a note'],
    'GET /api/v1/stations/{station}/handover' => ['module' => 'shifts', 'why' => 'shift bar: the handover'],
    'GET /api/v1/stations/{station}/shifts/current' => ['module' => 'shifts', 'why' => 'shift bar: the running shift'],
    'POST /api/v1/stations/{station}/shifts/open-ad-hoc' => ['module' => 'shifts', 'why' => 'shift bar: open an ad-hoc shift'],
    'POST /api/v1/stations/{station}/shifts/start' => ['module' => 'shifts', 'why' => 'shift bar: start'],
    'GET /api/v1/stock-counts/{count}' => ['module' => 'shifts', 'why' => 'count screen: one count'],
    'PUT /api/v1/stock-counts/{count}/cash' => ['module' => 'shifts', 'why' => 'till: the cash declaration'],
    'POST /api/v1/stock-counts/{count}/cosign' => ['module' => 'shifts', 'why' => 'handover: the incoming person co-signs'],
    'PUT /api/v1/stock-counts/{count}/lines' => ['module' => 'shifts', 'why' => 'count screen: draft the lines'],
    'POST /api/v1/stock-counts/{count}/reject' => ['module' => 'shifts', 'why' => 'handover: the incoming person rejects'],
    'POST /api/v1/stock-counts/{count}/submit' => ['module' => 'shifts', 'why' => 'count screen: submit'],
    'GET /api/v1/variance-reasons' => ['module' => 'shifts', 'why' => 'count screen: the reasons to explain a difference'],
    // shared
    'POST /api/v1/files' => ['module' => 'shared', 'why' => 'till: a receipt photo'],
    'DELETE /api/v1/files/{file}' => ['module' => 'shared', 'why' => 'till: take back an unattached receipt photo'],
];
