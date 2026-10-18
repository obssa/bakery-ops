<?php

declare(strict_types=1);

/*
 * Pest bootstrap. Groups are the backbone of the phase gates (see the bakery-ops-builder skill):
 *   arch, invariants, unit, feature, property, concurrency, module:<name>,
 *   contract:<consumer>-><provider>, gate:p<N>
 * Feature, Module, Contract and Gate tests get the Laravel TestCase + RefreshDatabase on Postgres.
 * Concurrency tests opt into DatabaseTruncation themselves (RefreshDatabase hides rows from a second connection).
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Modules', 'Contract', 'Gate', 'Invariants');

pest()->extend(TestCase::class)->in('Arch');

/*
 * Shared expectations. `toBeDecimal('12.5000')` compares decimal strings, never floats (ADR 0004).
 */
expect()->extend('toBeDecimal', function (string $expected) {
    return $this->toBeString()->toBe($expected);
});
