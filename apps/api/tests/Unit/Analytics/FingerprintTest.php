<?php

declare(strict_types=1);

use App\Modules\Analytics\Build\Fingerprint;

/*
 * The fingerprint of a rebuilt day (phase-4 plan M12, ADR 0014): sha256 of the canonical form of every summary row of the day, so two
 * rebuilds are compared with one string. Canonical: tables in name order, rows in primary-key order, keys sorted, values exactly as
 * stored (decimal strings keep their scale); rebuilt_at is left out (it moves with every run, the facts do not).
 */

it('is a sha256 hex string', function () {
    expect(Fingerprint::of(['daily_sales' => [['day' => '2026-11-20', 'revenue' => '1.50']]]))->toMatch('/^[0-9a-f]{64}$/');
})->group('unit', 'module:analytics');

it('does not depend on the order of the rows, of the keys or of the tables', function () {
    $a = [
        'daily_sales' => [['day' => '2026-11-20', 'revenue' => '1.50', 'orders_count' => 1]],
        'daily_hour_sales' => [['day' => '2026-11-20', 'hour' => 9, 'revenue' => '1.00'], ['day' => '2026-11-20', 'hour' => 10, 'revenue' => '0.50']],
    ];
    $b = [
        'daily_hour_sales' => [['hour' => 10, 'revenue' => '0.50', 'day' => '2026-11-20'], ['revenue' => '1.00', 'day' => '2026-11-20', 'hour' => 9]],
        'daily_sales' => [['orders_count' => 1, 'revenue' => '1.50', 'day' => '2026-11-20']],
    ];

    expect(Fingerprint::of($a))->toBe(Fingerprint::of($b));
})->group('unit', 'module:analytics');

it('is sensitive to the scale of a decimal, to a value, to a type and to the table a row is in', function () {
    $base = Fingerprint::of(['daily_sales' => [['day' => '2026-11-20', 'revenue' => '1.50', 'orders_count' => 1]]]);

    expect(Fingerprint::of(['daily_sales' => [['day' => '2026-11-20', 'revenue' => '1.5', 'orders_count' => 1]]]))->not->toBe($base)
        ->and(Fingerprint::of(['daily_sales' => [['day' => '2026-11-20', 'revenue' => '1.51', 'orders_count' => 1]]]))->not->toBe($base)
        ->and(Fingerprint::of(['daily_sales' => [['day' => '2026-11-20', 'revenue' => '1.50', 'orders_count' => '1']]]))->not->toBe($base)
        ->and(Fingerprint::of(['daily_hour_sales' => [['day' => '2026-11-20', 'revenue' => '1.50', 'orders_count' => 1]]]))->not->toBe($base);
})->group('unit', 'module:analytics');

it('leaves rebuilt_at out', function () {
    expect(Fingerprint::of(['daily_sales' => [['day' => '2026-11-20', 'revenue' => '1.50', 'rebuilt_at' => '2026-11-20 21:00:00.000001']]]))
        ->toBe(Fingerprint::of(['daily_sales' => [['day' => '2026-11-20', 'revenue' => '1.50', 'rebuilt_at' => '2026-11-21 05:00:00.123456']]]))
        ->toBe(Fingerprint::of(['daily_sales' => [['day' => '2026-11-20', 'revenue' => '1.50']]]));
})->group('unit', 'module:analytics');
