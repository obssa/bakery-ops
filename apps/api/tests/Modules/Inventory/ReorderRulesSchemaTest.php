<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

pest()->group('module:inventory', 'feature');

/*
 * Step 2.6a (phase-2 plan section 3, migration 14): reorder_rules holds the minimum an item may fall to at a place (or in the
 * whole branch when location_id is NULL) and how much to order. One LIVE rule per item and place, enforced by the database on
 * both engines: a rule that is soft-deleted frees its place, a second live one is refused. Quantities are DECIMAL(14,4) in
 * the item's base unit, min_qty may be zero, reorder_qty must be above zero. Configuration, so it soft-deletes and carries the
 * actor stamp.
 */

function ruleColumn(string $column): ?object
{
    return DB::selectOne(
        'select data_type as data_type, numeric_precision as p, numeric_scale as s, is_nullable as nullable, column_default as dflt
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), 'reorder_rules', $column],
    );
}

/** @return array<string, mixed> a raw rule row for a fresh branch and item; $over replaces any column, `_reuse` keeps the last branch and item */
function ruleRow(array $over = []): array
{
    static $branch = null, $item = null, $location = null, $user = null;
    if (! ($over['_reuse'] ?? false) || $branch === null) {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $item = Item::factory()->ingredient('g')->create(['branch_id' => $branch->id, 'created_by_user_id' => $user->id]);
        $location = Location::factory()->ofType(LocationType::Store)->create(['branch_id' => $branch->id]);
    }
    unset($over['_reuse']);

    return $over + [
        'branch_id' => $branch->id, 'item_id' => $item->id, 'location_id' => $location->id, 'location_key' => $location->id,
        'min_qty' => '5000.0000', 'reorder_qty' => '25000.0000', 'notify_roles' => json_encode(['admin', 'manager']), 'is_active' => 1,
        'created_by_user_id' => $user->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
    ];
}

it('creates reorder_rules with branch_id NOT NULL, DECIMAL(14,4) quantities, a JSON role list and the actor stamp', function () {
    expect(Schema::hasTable('reorder_rules'))->toBeTrue();
    expect(strtoupper((string) ruleColumn('branch_id')?->nullable))->toBe('NO');
    foreach (['min_qty', 'reorder_qty'] as $column) {
        $c = ruleColumn($column);
        expect($c)->not->toBeNull($column)
            ->and((int) $c?->p)->toBe(14)
            ->and((int) $c?->s)->toBe(4)
            ->and(strtoupper((string) $c?->nullable))->toBe('NO');
    }
    expect(strtoupper((string) ruleColumn('location_id')?->nullable))->toBe('YES', 'a rule without a place is branch-wide')
        ->and(strtoupper((string) ruleColumn('location_key')?->nullable))->toBe('NO')
        ->and((string) ruleColumn('location_key')?->dflt)->toBe('0')
        ->and(Schema::hasColumns('reorder_rules', ['notify_roles', 'is_active', 'created_by_user_id', 'acting_user_id', 'shift_instance_id', 'actor_station_id', 'device_id', 'version', 'created_at', 'updated_at', 'deleted_at']))->toBeTrue();
    $fk = DB::selectOne(
        "select 1 as ok from information_schema.key_column_usage
          where table_schema = ? and table_name = 'reorder_rules' and column_name = 'item_id' and referenced_table_name = 'items'",
        [dbSchema()],
    );
    expect($fk)->not->toBeNull();
});

it('stores quantities with four decimals and the role list as given', function () {
    DB::table('reorder_rules')->insert(ruleRow(['min_qty' => '0', 'reorder_qty' => '0.0001']));

    $row = DB::table('reorder_rules')->sole();
    expect($row->min_qty)->toBe('0.0000')->and($row->reorder_qty)->toBe('0.0001')
        ->and(json_decode((string) $row->notify_roles, true))->toBe(['admin', 'manager']);
});

it('refuses a negative minimum and a reorder quantity of zero or less', function () {
    expect(Schema::hasTable('reorder_rules'))->toBeTrue();
    DB::table('reorder_rules')->insert(ruleRow());

    expect(fn () => DB::table('reorder_rules')->insert(ruleRow(['min_qty' => '-0.0001'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('reorder_rules')->insert(ruleRow(['reorder_qty' => '0'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('reorder_rules')->insert(ruleRow(['reorder_qty' => '-1'])))->toThrow(QueryException::class);
    expect(DB::table('reorder_rules')->count())->toBe(1);
});

it('allows one live rule per item and place, and frees the place when the rule is soft-deleted', function () {
    DB::table('reorder_rules')->insert(ruleRow());

    expect(fn () => DB::table('reorder_rules')->insert(ruleRow(['_reuse' => true])))->toThrow(QueryException::class);

    DB::table('reorder_rules')->update(['deleted_at' => now()]);
    DB::table('reorder_rules')->insert(ruleRow(['_reuse' => true]));
    DB::table('reorder_rules')->insert(ruleRow(['_reuse' => true, 'deleted_at' => now()]));
    DB::table('reorder_rules')->insert(ruleRow(['_reuse' => true, 'deleted_at' => now()]));

    expect(DB::table('reorder_rules')->count())->toBe(4)
        ->and(DB::table('reorder_rules')->whereNull('deleted_at')->count())->toBe(1);
});

it('allows a branch-wide rule beside a rule for a place of the same item, and refuses two branch-wide live rules', function () {
    DB::table('reorder_rules')->insert(ruleRow());
    DB::table('reorder_rules')->insert(ruleRow(['_reuse' => true, 'location_id' => null, 'location_key' => 0]));

    expect(fn () => DB::table('reorder_rules')->insert(ruleRow(['_reuse' => true, 'location_id' => null, 'location_key' => 0])))->toThrow(QueryException::class);
    expect(DB::table('reorder_rules')->count())->toBe(2);
});

it('treats an inactive rule as still occupying its place (it is switched back on, not duplicated)', function () {
    DB::table('reorder_rules')->insert(ruleRow(['is_active' => 0]));

    expect(fn () => DB::table('reorder_rules')->insert(ruleRow(['_reuse' => true])))->toThrow(QueryException::class);
});
