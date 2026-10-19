<?php

declare(strict_types=1);

/*
 * Registry route -> permission -> roles allowed by default (rule 6; architecture.md section 7 matrix).
 * tests/Invariants/RoutePermissionCoverageTest.php fails when an api/v1 route is neither public nor listed here;
 * from step 0.4 on, tests/Feature/RoutePermissionMatrixTest.php hits every entry as every role expecting 403 or not.
 *
 *   'GET /api/v1/items' => ['permission' => 'catalog.view', 'roles' => ['admin', 'manager', 'cashier', ...]],
 *
 * Step 0.2 (Shared) registers no endpoint.
 */
return [];
