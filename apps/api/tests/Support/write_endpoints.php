<?php

declare(strict_types=1);

/*
 * Registry of every write endpoint under /api/v1 (rule 7, ADR 0003). tests/Invariants/IdempotencyCoverageTest.php
 * drives assertIdempotent() over every entry and fails when a write route exists that is not listed here.
 *
 * Each entry:
 *   [
 *     'method'  => 'POST',
 *     'uri'     => '/api/v1/items',
 *     'module'  => 'catalog',
 *     'table'   => 'items',                                  // the row the write creates
 *     'setup'   => fn (Tests\TestCase $t): void => ...,      // seed, actInBranch(), actingAs(admin) ...
 *     'payload' => fn (): array => ['kind' => 'product', ...],
 *   ]
 *
 * Step 0.2 (Shared) registers no endpoint; the first entries arrive with Identity (0.4) and Catalog (0.5).
 */
return [];
