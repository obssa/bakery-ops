<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * Rule 9 at the schema level: any *_en column has its *_am sibling and vice versa, and the user-facing tables
 * carry the pair (stations now; items, categories, modifiers, recipe_steps, payment_channels, variance_reasons
 * as their modules land). Asked of information_schema so it holds on MySQL 8 and PostgreSQL alike (ADR 0006).
 */

$userFacing = [
    'stations' => 'name',
    'items' => 'name',
    'categories' => 'name',
    'modifiers' => 'name',
    'payment_channels' => 'name',
    'variance_reasons' => 'name',
    'recipe_steps' => 'text',
];

it('pairs every _en column with an _am column', function () {
    $rows = DB::select(
        'select table_name as table_name, column_name as column_name from information_schema.columns where table_schema = ?',
        [dbSchema()],
    );
    $byTable = [];
    foreach ($rows as $row) {
        $byTable[$row->table_name][] = $row->column_name;
    }
    $problems = [];
    foreach ($byTable as $table => $columns) {
        foreach ($columns as $column) {
            if (str_ends_with($column, '_en') && ! in_array(substr($column, 0, -3).'_am', $columns, true)) {
                $problems[] = "{$table}.{$column} has no _am sibling";
            }
            if (str_ends_with($column, '_am') && ! in_array(substr($column, 0, -3).'_en', $columns, true)) {
                $problems[] = "{$table}.{$column} has no _en sibling";
            }
        }
    }
    expect($problems)->toBe([]);
})->group('invariants');

it('gives every user-facing table that exists its bilingual pair', function () use ($userFacing) {
    $checked = 0;
    foreach ($userFacing as $table => $base) {
        $columns = array_map(fn ($c) => $c->column_name, DB::select(
            'select column_name as column_name from information_schema.columns where table_schema = ? and table_name = ?',
            [dbSchema(), $table],
        ));
        if ($columns === []) {
            continue; // the module that owns it has not landed yet
        }
        $checked++;
        expect($columns)->toContain("{$base}_en", "{$base}_am");
    }
    expect($checked)->toBeGreaterThan(0, 'stations must exist from step 0.2');
})->group('invariants');
