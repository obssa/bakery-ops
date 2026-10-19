<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * ADR 0004 at the schema level: no float anywhere; quantities DECIMAL(14,4); money DECIMAL(12,2).
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

it('stores quantities as DECIMAL(14,4) and money as DECIMAL(12,2)', function () use ($columns) {
    $wrong = [];
    foreach ($columns() as $c) {
        if (! in_array(strtolower((string) $c->data_type), ['numeric', 'decimal'], true)) {
            continue;
        }
        $name = $c->column_name;
        $scale = "({$c->numeric_precision},{$c->numeric_scale})";
        if (preg_match('/(^qty$|_qty$|quantity)/i', $name) === 1 && $scale !== '(14,4)') {
            $wrong[] = "{$c->table_name}.{$name} is decimal{$scale}, quantities are decimal(14,4)";
        } elseif (preg_match('/(price|amount|total|cost|subtotal|discount|tax|deposit|float)/i', $name) === 1
            && ! str_ends_with($name, '_pct') && $scale !== '(12,2)') {
            $wrong[] = "{$c->table_name}.{$name} is decimal{$scale}, money is decimal(12,2)";
        }
    }
    expect($wrong)->toBe([]);
})->group('invariants');
