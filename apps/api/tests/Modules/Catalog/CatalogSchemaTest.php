<?php

declare(strict_types=1);

use App\Modules\Catalog\Support\StandardUnits;
use App\Shared\Http\ApiError;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Catalog tables as domain-model.md lists them, asked of the real database (MySQL 8 locally, MariaDB 10.11 in CI
 * and live; portable to PostgreSQL, ADR 0006): DECIMAL scales for money, quantities and percentages, branch_id on
 * every table but the global units, the bilingual pairs, the actor stamp on items and prices, soft deletes on the
 * mutable tables, and the standard units every database starts with.
 */

const CATALOG_TABLES = ['units', 'categories', 'items', 'item_prices', 'item_station_assignments', 'recipes', 'recipe_lines', 'recipe_steps', 'modifiers'];

function catalogColumn(string $table, string $column): ?object
{
    return DB::selectOne(
        'select data_type as data_type, numeric_precision as p, numeric_scale as s, is_nullable as nullable
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), $table, $column],
    );
}

it('creates every catalog table, each with branch_id NOT NULL and a foreign key except the global units', function () {
    foreach (CATALOG_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }

    expect(Schema::hasColumn('units', 'branch_id'))->toBeFalse('units are global reference data (BRANCH_ALLOWLIST)');
    foreach (array_diff(CATALOG_TABLES, ['units']) as $table) {
        $column = catalogColumn($table, 'branch_id');
        expect($column)->not->toBeNull("{$table}.branch_id")
            ->and(strtoupper((string) $column?->nullable))->toBe('NO', "{$table}.branch_id must be NOT NULL");

        $fk = DB::selectOne(
            "select 1 as ok from information_schema.key_column_usage
              where table_schema = ? and table_name = ? and column_name = 'branch_id' and referenced_table_name = 'branches'",
            [dbSchema(), $table],
        );
        expect($fk)->not->toBeNull("{$table}.branch_id must reference branches");
    }
})->group('module:catalog', 'invariants');

it('stores money as DECIMAL(12,2), quantities and unit factors as DECIMAL(14,4), waste as DECIMAL(5,2)', function () {
    $expected = [
        'units.factor' => '14,4',
        'items.default_batch_qty' => '14,4',
        'item_prices.price' => '12,2',
        'recipes.yield_qty' => '14,4',
        'recipe_lines.qty' => '14,4',
        'recipe_lines.waste_pct' => '5,2',
        'modifiers.price_delta' => '12,2',
    ];
    foreach ($expected as $path => $scale) {
        [$table, $name] = explode('.', $path);
        $column = catalogColumn($table, $name);
        expect($column)->not->toBeNull($path)
            ->and(strtolower((string) $column?->data_type))->toBeIn(['decimal', 'numeric'], $path)
            ->and("{$column?->p},{$column?->s}")->toBe($scale, $path);
    }
})->group('module:catalog', 'invariants');

it('pairs the bilingual columns of every user-facing catalog table', function () {
    foreach (['units' => 'name', 'categories' => 'name', 'items' => 'name', 'modifiers' => 'name', 'recipe_steps' => 'text'] as $table => $base) {
        expect(Schema::hasColumns($table, ["{$base}_en", "{$base}_am"]))->toBeTrue("{$table} needs {$base}_en and {$base}_am");
        expect(strtoupper((string) catalogColumn($table, "{$base}_am")?->nullable))->toBe('NO', "{$table}.{$base}_am is required");
    }
})->group('module:catalog', 'invariants');

it('carries the item columns Inventory, Orders and Production rely on, and the actor stamp on items and prices', function () {
    expect(Schema::hasColumns('items', [
        'id', 'branch_id', 'kind', 'fulfilment', 'name_en', 'name_am', 'category_id', 'unit_id', 'sku', 'is_active',
        'is_new_until', 'default_batch_qty', 'production_station_id', 'created_at', 'updated_at', 'deleted_at',
        // step 4.1b: the admin's two refund settings (Ramin's answer to R-C, 2026-10-28)
        'refundable', 'refund_restock',
    ]))->toBeTrue();
    foreach (['refundable' => '1', 'refund_restock' => '0'] as $column => $default) {
        $info = DB::selectOne(
            'select is_nullable as nullable, column_default as def from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
            [dbSchema(), 'items', $column],
        );
        expect(strtoupper((string) $info?->nullable))->toBe('NO', "items.{$column} is NOT NULL")
            ->and(trim((string) $info?->def, "'"))->toBeIn([$default, $default === '1' ? 'true' : 'false'], "items.{$column} default");
    }

    foreach (['items', 'item_prices'] as $table) {
        expect(Schema::hasColumns($table, ['created_by_user_id', 'acting_user_id', 'shift_instance_id', 'station_id', 'device_id']))
            ->toBeTrue("{$table} carries the actor stamp (rule 4)")
            ->and(strtoupper((string) catalogColumn($table, 'created_by_user_id')?->nullable))->toBe('NO')
            ->and(strtoupper((string) catalogColumn($table, 'acting_user_id')?->nullable))->toBe('YES');
    }

    expect(Schema::hasColumns('item_prices', ['item_id', 'price', 'currency', 'effective_from', 'effective_to']))->toBeTrue()
        ->and(Schema::hasColumns('recipes', ['product_item_id', 'yield_qty', 'yield_unit_id', 'version', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumns('recipe_lines', ['recipe_id', 'ingredient_item_id', 'qty', 'unit_id', 'is_optional', 'waste_pct']))->toBeTrue()
        ->and(Schema::hasColumns('recipe_steps', ['recipe_id', 'step_order', 'text_en', 'text_am']))->toBeTrue()
        ->and(Schema::hasColumns('modifiers', ['item_id', 'name_en', 'name_am', 'price_delta', 'recipe_delta']))->toBeTrue()
        ->and(Schema::hasColumns('item_station_assignments', ['item_id', 'station_id']))->toBeTrue()
        ->and(Schema::hasColumns('units', ['code', 'name_en', 'name_am', 'base_unit_id', 'factor']))->toBeTrue();
})->group('module:catalog', 'invariants');

it('soft-deletes the mutable catalog tables and keeps price history without deleted_at', function () {
    foreach (array_diff(CATALOG_TABLES, ['item_prices']) as $table) {
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue("{$table}.deleted_at (rule 4)");
    }
    expect(Schema::hasColumn('item_prices', 'deleted_at'))->toBeFalse('a price is closed by effective_to, never deleted');
})->group('module:catalog', 'invariants');

it('seeds kg g L ml pcs slice tray on the base units g, ml and pcs', function () {
    $rows = DB::table('units')->whereNull('deleted_at')->get()->keyBy('code');

    expect($rows->keys()->sort()->values()->all())->toBe(collect(StandardUnits::codes())->sort()->values()->all())
        ->and(StandardUnits::codes())->toBe(['g', 'ml', 'pcs', 'kg', 'L', 'slice', 'tray']);

    foreach (StandardUnits::ALL as $code => $spec) {
        $row = $rows->get($code);
        expect($row)->not->toBeNull("unit {$code}")
            ->and($row->name_en)->toBe($spec['name_en'])
            ->and($row->name_am)->toBe($spec['name_am'])
            ->and((string) $row->factor)->toBe($spec['factor'], "{$code}.factor");
        $base = $spec['base'] === null ? null : $rows->get($spec['base'])?->id;
        expect($row->base_unit_id === null ? null : (int) $row->base_unit_id)->toBe($base === null ? null : (int) $base, "{$code}.base_unit_id");
    }

    expect($rows->whereNull('base_unit_id')->keys()->sort()->values()->all())->toBe(['g', 'ml', 'pcs'])
        ->and((string) $rows->get('kg')?->factor)->toBe('1000.0000')
        ->and((string) $rows->get('L')?->factor)->toBe('1000.0000');
})->group('module:catalog', 'invariants');

it('constrains kind and fulfilment at the database level and keeps unit codes unique', function () {
    seedRoles();
    $branch = Branch::factory()->create();
    $user = makeUser($branch, 'admin');
    $row = fn (array $over): array => $over + [
        'branch_id' => $branch->id, 'kind' => 'ingredient', 'fulfilment' => 'bought_in', 'name_en' => 'Flour', 'name_am' => 'ዱቄት',
        'unit_id' => catalogUnit('kg')->id, 'is_active' => true, 'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
    ];

    DB::table('items')->insert($row(['sku' => 'OK-1']));
    expect(fn () => DB::table('items')->insert($row(['kind' => 'service'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('items')->insert($row(['fulfilment' => 'dropship'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('categories')->insert(['branch_id' => $branch->id, 'kind' => 'other', 'name_en' => 'x', 'name_am' => 'x', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('units')->insert(['code' => 'kg', 'name_en' => 'again', 'name_am' => 'again', 'factor' => '1.0000', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
})->group('module:catalog', 'invariants');

it('keeps sku unique per branch and lets two branches share one', function () {
    $a = Branch::factory()->create();
    $b = Branch::factory()->create();
    seedRoles();
    $user = makeUser($a, 'admin');
    $row = fn (int $branchId): array => [
        'branch_id' => $branchId, 'kind' => 'product', 'fulfilment' => 'to_stock', 'name_en' => 'Cake', 'name_am' => 'ኬክ',
        'unit_id' => catalogUnit('pcs')->id, 'sku' => 'CAKE-1', 'is_active' => true, 'created_by_user_id' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ];

    DB::table('items')->insert($row($a->id));
    DB::table('items')->insert($row($b->id));

    expect(fn () => DB::table('items')->insert($row($a->id)))->toThrow(QueryException::class);
})->group('module:catalog', 'invariants');

it('keeps the en and am catalog dictionaries in step, and ApiError reads them', function () {
    $en = Arr::dot(require base_path('lang/en/catalog.php'));
    $am = Arr::dot(require base_path('lang/am/catalog.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en))->and($en)->not->toBeEmpty();
    foreach ($en as $key => $value) {
        expect($value)->toBeString()->not->toBeEmpty("en {$key} is empty")
            ->and($am[$key])->toBeString()->not->toBeEmpty("am {$key} is empty")->not->toBe($value, "am {$key} is still English");
    }

    $body = ApiError::body('catalog.recipe.bought_in');
    expect($body['message_en'])->toBe($en['recipe.bought_in'])->and($body['message_am'])->toBe($am['recipe.bought_in']);
})->group('module:catalog', 'invariants');
