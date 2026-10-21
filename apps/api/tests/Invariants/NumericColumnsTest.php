<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * ADR 0004 at the schema level: no float anywhere; quantities DECIMAL(14,4); money DECIMAL(12,2); cost rates (a column
 * named *_cost or cost_*, a price per base unit) DECIMAL(14,4), their totals named *_total DECIMAL(12,2) (invariants
 * version 2, phase-2 plan section 3, ADR 0004 note of 2026-10-21).
 * scripts/check_invariants.py --numeric lints the migrations; this test asks the real database, through
 * information_schema so it holds on MySQL 8 (float/double, decimal) and PostgreSQL (real/double precision, numeric).
 */

$columns = fn (): array => DB::select(
    'select table_name as table_name, column_name as column_name, data_type as data_type,
            numeric_precision as numeric_precision, numeric_scale as numeric_scale
     from information_schema.columns where table_schema = ? order by table_name, column_name',
    [dbSchema()],
);

it('has no floating-point columns', function () use ($columns) {
    $floats = array_filter(
        $columns(),
        fn ($c) => in_array(strtolower((string) $c->data_type), ['real', 'double precision', 'float', 'double'], true),
    );

    expect(array_map(fn ($c) => "{$c->table_name}.{$c->column_name}", $floats))->toBe([]);
})->group('invariants');

/**
 * The rule for one decimal column; null when it is right. Shared by the scan of the real database and by the planted
 * cases below, which keep the rule itself under test.
 */
function numericColumnProblem(string $table, string $name, int $precision, int $scale): ?string
{
    $scaleOf = "({$precision},{$scale})";
    if (preg_match('/(^qty$|_qty$|quantity)/i', $name) === 1) {
        return $scaleOf !== '(14,4)' ? "{$table}.{$name} is decimal{$scaleOf}, quantities are decimal(14,4)" : null;
    }
    // a rate per base unit: a name that has the word `cost` in it (unit_cost, unit_cost_base, moving_avg_cost, cost_after)
    // unless it is a total of money (line_total, cost_total), which stays money
    if (preg_match('/(^|_)cost(_|$)/i', $name) === 1 && ! str_ends_with($name, '_total')) {
        return $scaleOf !== '(14,4)' ? "{$table}.{$name} is decimal{$scaleOf}, a cost rate (per base unit) is decimal(14,4); a total of money is named *_total" : null;
    }
    if (preg_match('/(price|amount|total|subtotal|discount|tax|deposit|float)/i', $name) === 1
        && ! str_ends_with($name, '_pct') && $scaleOf !== '(12,2)') {
        return "{$table}.{$name} is decimal{$scaleOf}, money is decimal(12,2)";
    }

    return null;
}

it('stores quantities as DECIMAL(14,4) and money as DECIMAL(12,2)', function () use ($columns) {
    $wrong = [];
    foreach ($columns() as $c) {
        if (! in_array(strtolower((string) $c->data_type), ['numeric', 'decimal'], true)) {
            continue;
        }
        $problem = numericColumnProblem((string) $c->table_name, (string) $c->column_name, (int) $c->numeric_precision, (int) $c->numeric_scale);
        if ($problem !== null) {
            $wrong[] = $problem;
        }
    }
    expect($wrong)->toBe([]);
})->group('invariants');

it('refuses a planted cost rate held in two decimals', function () {
    expect(numericColumnProblem('purchase_lines', 'unit_cost', 12, 2))->toContain('cost rate')->toContain('decimal(14,4)')
        ->and(numericColumnProblem('item_costs', 'moving_avg_cost', 12, 2))->toContain('cost rate')
        ->and(numericColumnProblem('t', 'cost_before', 12, 2))->toContain('cost rate');
})->group('invariants');

it('accepts cost rates at DECIMAL(14,4) and totals of money at DECIMAL(12,2)', function (string $name, int $p, int $s) {
    expect(numericColumnProblem('t', $name, $p, $s))->toBeNull();
})->with([
    ['unit_cost', 14, 4],
    ['unit_cost_base', 14, 4],
    ['moving_avg_cost', 14, 4],
    ['cost_before', 14, 4],
    ['cost_after', 14, 4],
    ['unit_cost_in', 14, 4],
    ['line_total', 12, 2],
    ['cost_total', 12, 2],
    ['total', 12, 2],
    ['price', 12, 2],
    ['qty_base', 14, 4],
])->group('invariants');

it('refuses a total of money that is not DECIMAL(12,2), a cost-named total included', function (string $name) {
    expect(numericColumnProblem('t', $name, 14, 4))->toContain('money is decimal(12,2)');
})->with(['line_total', 'cost_total', 'price', 'paid_total'])->group('invariants');
