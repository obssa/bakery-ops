<?php

declare(strict_types=1);

/*
 * Pest bootstrap. Groups are the backbone of the phase gates (see the bakery-ops-builder skill):
 *   arch, invariants, unit, feature, property, concurrency, module:<name>,
 *   contract:<consumer>-><provider>, gate:p<N>, seeds (the demo bakery, tests/Seeders)
 * Feature, Module, Contract, Invariants and Seeders tests get the Laravel TestCase + RefreshDatabase on the production engine
 * (MySQL 8 locally, MariaDB 10.11 in CI and live). Gate tests get the TestCase and choose their database trait per
 * file: RefreshDatabase for most, Tests\Support\CommitsForConcurrency (DatabaseTruncation) for the concurrency tests,
 * because RefreshDatabase's wrapping transaction hides the rows from a second connection (traps.md, Pest + Postgres).
 * tests/Unit stays on the plain PHPUnit TestCase: value objects and state machines must work without the container.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/Support/helpers.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Modules', 'Contract', 'Invariants', 'Seeders');

pest()->extend(TestCase::class)->in('Arch', 'Gate');

/*
 * Shared expectations. `toBeDecimal('12.5000')` compares decimal strings, never floats (ADR 0004).
 */
expect()->extend('toBeDecimal', function (string $expected) {
    return $this->toBeString()->toBe($expected);
});
