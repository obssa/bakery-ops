<?php

declare(strict_types=1);

/*
 * Pest bootstrap. Groups are the backbone of the phase gates (see the bakery-ops-builder skill):
 *   arch, invariants, unit, feature, property, concurrency, module:<name>,
 *   contract:<consumer>-><provider>, gate:p<N>, seeds (the demo bakery, tests/Seeders), seeds:p4 (its Phase 4 history, run after Analytics)
 * Feature, Module, Contract, Invariants and Seeders tests get the Laravel TestCase + RefreshDatabase on the production engine
 * (MariaDB 10.11 locally, in CI and live). Gate tests, and contract tests named *ConcurrencyTest.php, get the TestCase
 * and choose their database trait per file: RefreshDatabase for most, Tests\Support\CommitsForConcurrency
 * (DatabaseTruncation) for the concurrency tests, because RefreshDatabase's wrapping transaction hides the rows from a
 * second connection or a forked process (traps.md, Pest + Postgres). Contract files are matched by name for that
 * reason: Contract/<Consumer>/*ContractTest.php get RefreshDatabase, Contract/<Consumer>/*ConcurrencyTest.php do not.
 * tests/Unit stays on the plain PHPUnit TestCase: value objects and state machines must work without the container.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/Support/helpers.php';
require_once __DIR__.'/Support/images.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Modules', 'Contract/*/*ContractTest.php', 'Invariants', 'Seeders');

pest()->extend(TestCase::class)->in('Arch', 'Gate', 'Contract/*/*ConcurrencyTest.php');

/*
 * Shared expectations. `toBeDecimal('12.5000')` compares decimal strings, never floats (ADR 0004).
 */
expect()->extend('toBeDecimal', function (string $expected) {
    return $this->toBeString()->toBe($expected);
});
