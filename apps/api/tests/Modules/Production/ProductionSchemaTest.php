<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Models\Location;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

pest()->group('module:production', 'feature');

/*
 * Step 2.7a (phase-2 plan section 3, migrations 15 and 16): production_requests and production_batches asked of the database
 * itself. A request asks a station to make a to-stock product; one batch is the work done for it. branch_id NOT NULL with a
 * foreign key, the keys that make a replay and a racing stock-out job find one row, the CHECKs behind both machines and the
 * batch's quantities and sign-off. Nothing here is deleted (status ends a row), so there is no deleted_at.
 */

function prodColumn(string $table, string $column): ?object
{
    return DB::selectOne(
        'select data_type as data_type, numeric_precision as p, numeric_scale as s, is_nullable as nullable
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), $table, $column],
    );
}

/** @return array{branch: Branch, user: User, other: User, station: int, product: int, recipe: int, store: int, display: int} */
function prodParents(): array
{
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $product = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $branch->id, 'created_by_user_id' => $user->id]);

    return [
        'branch' => $branch,
        'user' => $user,
        'other' => User::factory()->create(['branch_id' => $branch->id]),
        'station' => Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $branch->id])->id,
        'product' => $product->id,
        'recipe' => Recipe::factory()->create(['product_item_id' => $product->id])->id,
        'store' => Location::factory()->ofType(LocationType::Kitchen)->create(['branch_id' => $branch->id])->id,
        'display' => Location::factory()->ofType(LocationType::DisplayCase)->create(['branch_id' => $branch->id])->id,
    ];
}

/**
 * @param  array<string, mixed>  $p
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function rawRequest(array $p, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'client_uuid' => (string) Str::uuid(), 'product_item_id' => $p['product'],
        'requested_qty' => '12.0000', 'due_at' => null, 'priority' => 'normal', 'station_id' => $p['station'], 'reason' => 'manual',
        'status' => 'requested', 'requested_by_user_id' => $p['user']->id, 'assigned_to_user_id' => null,
        'linked_order_id' => null, 'source_alert_id' => null, 'open_stock_out_key' => null, 'note' => null,
        'created_by_user_id' => $p['user']->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];
}

/**
 * @param  array<string, mixed>  $p
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function rawBatch(array $p, int $requestId, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'client_uuid' => (string) Str::uuid(), 'request_id' => $requestId, 'station_id' => $p['station'],
        'status' => 'started', 'recipe_id' => $p['recipe'], 'recipe_version' => 1, 'planned_qty' => '12.0000',
        'produced_qty' => null, 'waste_qty' => null, 'consume_location_id' => $p['store'], 'output_location_id' => $p['display'],
        'started_at' => now(), 'started_by_user_id' => $p['user']->id,
        'finished_at' => null, 'finished_by_user_id' => null, 'confirmed_at' => null, 'confirmed_by_user_id' => null,
        'send_back_note' => null, 'send_back_count' => 0, 'output_movement_id' => null,
        'created_by_user_id' => $p['user']->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];
}

it('creates both tables with branch_id NOT NULL and a foreign key to branches', function () {
    foreach (['production_requests', 'production_batches'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
        expect(strtoupper((string) prodColumn($table, 'branch_id')?->nullable))->toBe('NO');
        $fk = DB::selectOne(
            "select 1 as ok from information_schema.key_column_usage
              where table_schema = ? and table_name = ? and column_name = 'branch_id' and referenced_table_name = 'branches'",
            [dbSchema(), $table],
        );
        expect($fk)->not->toBeNull();
    }
});

it('stores every quantity as DECIMAL(14,4), never soft-deletes, and stamps who did it', function () {
    foreach ([['production_requests', 'requested_qty'], ['production_batches', 'planned_qty'], ['production_batches', 'produced_qty'], ['production_batches', 'waste_qty']] as [$table, $column]) {
        $c = prodColumn($table, $column);
        expect([(int) $c?->p, (int) $c?->s])->toBe([14, 4], "{$table}.{$column}");
    }
    foreach (['production_requests', 'production_batches'] as $table) {
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeFalse("{$table} ends by status")
            ->and(Schema::hasColumns($table, ['client_uuid', 'created_by_user_id', 'acting_user_id', 'shift_instance_id', 'actor_station_id', 'device_id', 'version']))->toBeTrue();
    }
});

it('keeps a request client_uuid once per branch and refuses a missing product, station or user', function () {
    $p = prodParents();
    $uuid = (string) Str::uuid();
    DB::table('production_requests')->insert(rawRequest($p, ['client_uuid' => $uuid]));

    expect(fn () => DB::table('production_requests')->insert(rawRequest($p, ['client_uuid' => $uuid])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_requests')->insert(rawRequest($p, ['product_item_id' => 999999])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_requests')->insert(rawRequest($p, ['station_id' => 999999])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_requests')->insert(rawRequest($p, ['requested_by_user_id' => 999999])))->toThrow(QueryException::class);

    DB::table('production_requests')->insert(rawRequest(prodParents(), ['client_uuid' => $uuid]));
    expect(DB::table('production_requests')->where('client_uuid', $uuid)->count())->toBe(2);
});

it('keeps the request quantity positive and its status, priority and reason to the enum lists', function () {
    $p = prodParents();

    expect(fn () => DB::table('production_requests')->insert(rawRequest($p, ['requested_qty' => '0.0000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_requests')->insert(rawRequest($p, ['requested_qty' => '-1.0000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_requests')->insert(rawRequest($p, ['status' => 'confirmed'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_requests')->insert(rawRequest($p, ['priority' => 'low'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_requests')->insert(rawRequest($p, ['reason' => 'auto'])))->toThrow(QueryException::class);

    foreach (['requested', 'accepted', 'in_progress', 'pending_confirmation', 'done', 'cancelled'] as $status) {
        DB::table('production_requests')->insert(rawRequest($p, ['status' => $status, 'requested_qty' => '0.0001']));
    }
    foreach (['normal', 'high', 'urgent'] as $priority) {
        DB::table('production_requests')->insert(rawRequest($p, ['priority' => $priority]));
    }
    foreach (['schedule', 'stock_out', 'manual'] as $reason) {
        DB::table('production_requests')->insert(rawRequest($p, ['reason' => $reason]));
    }
    expect(DB::table('production_requests')->count())->toBe(12);
});

it('lets the database refuse a second open stock-out request for one product', function () {
    $p = prodParents();
    $open = ['reason' => 'stock_out', 'open_stock_out_key' => 1, 'source_alert_id' => 7];
    DB::table('production_requests')->insert(rawRequest($p, $open));

    // two jobs racing on one stock-out: the second insert is refused by the unique key, not by a SELECT before it
    expect(fn () => DB::table('production_requests')->insert(rawRequest($p, $open)))->toThrow(QueryException::class);

    // closed ones (key NULL) stack, another product has its own, and the key only means a stock-out request
    DB::table('production_requests')->insert(rawRequest($p, ['reason' => 'stock_out', 'status' => 'done']));
    DB::table('production_requests')->insert(rawRequest($p, ['reason' => 'stock_out', 'status' => 'cancelled']));
    DB::table('production_requests')->insert(rawRequest($p, ['reason' => 'schedule']));
    DB::table('production_requests')->insert(rawRequest($p, ['reason' => 'schedule']));
    $second = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $p['branch']->id, 'created_by_user_id' => $p['user']->id]);
    DB::table('production_requests')->insert(rawRequest($p, $open + ['product_item_id' => $second->id]));

    expect(fn () => DB::table('production_requests')->insert(rawRequest($p, ['reason' => 'manual', 'open_stock_out_key' => 1, 'product_item_id' => $second->id])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_requests')->insert(rawRequest($p, $open + ['open_stock_out_key' => 2])))->toThrow(QueryException::class);
});

it('stores the batch with one batch per request, a client_uuid once per branch and its places known', function () {
    $p = prodParents();
    $request = DB::table('production_requests')->insertGetId(rawRequest($p));
    $uuid = (string) Str::uuid();
    DB::table('production_batches')->insert(rawBatch($p, $request, ['client_uuid' => $uuid]));

    expect(fn () => DB::table('production_batches')->insert(rawBatch($p, $request)))->toThrow(QueryException::class, '', 'one batch per request')
        ->and(fn () => DB::table('production_batches')->insert(rawBatch($p, DB::table('production_requests')->insertGetId(rawRequest($p)), ['client_uuid' => $uuid])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert(rawBatch($p, DB::table('production_requests')->insertGetId(rawRequest($p)), ['recipe_id' => 999999])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert(rawBatch($p, DB::table('production_requests')->insertGetId(rawRequest($p)), ['output_location_id' => 999999])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert(rawBatch($p, 999999)))->toThrow(QueryException::class);
});

it('keeps the batch status, planned quantity and produced and waste quantities to the plan', function () {
    $p = prodParents();
    $row = fn (array $over): array => rawBatch($p, DB::table('production_requests')->insertGetId(rawRequest($p)), $over);
    $done = ['status' => 'finished', 'finished_at' => now(), 'finished_by_user_id' => $p['user']->id];

    expect(fn () => DB::table('production_batches')->insert($row(['status' => 'done'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert($row(['planned_qty' => '0.0000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert($row(['produced_qty' => '-0.0001'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert($row(['waste_qty' => '-0.0001'])))->toThrow(QueryException::class)
        // finished with nothing produced and nothing wasted, or with one of them missing
        ->and(fn () => DB::table('production_batches')->insert($row($done + ['produced_qty' => '0.0000', 'waste_qty' => '0.0000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert($row($done + ['produced_qty' => '5.0000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert($row($done)))->toThrow(QueryException::class);

    DB::table('production_batches')->insert($row($done + ['produced_qty' => '0.0000', 'waste_qty' => '3.0000']));
    DB::table('production_batches')->insert($row($done + ['produced_qty' => '12.0000', 'waste_qty' => '0.0000']));
    DB::table('production_batches')->insert($row(['produced_qty' => '0.0000']));
    expect(DB::table('production_batches')->count())->toBe(3);
});

it('requires who and when finished for a finished or confirmed batch', function () {
    $p = prodParents();
    $row = fn (array $over): array => rawBatch($p, DB::table('production_requests')->insertGetId(rawRequest($p)), $over + ['produced_qty' => '12.0000', 'waste_qty' => '0.0000']);

    expect(fn () => DB::table('production_batches')->insert($row(['status' => 'finished'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert($row(['status' => 'finished', 'finished_at' => now()])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert($row(['status' => 'finished', 'finished_by_user_id' => $p['user']->id])))->toThrow(QueryException::class);

    DB::table('production_batches')->insert($row(['status' => 'finished', 'finished_at' => now(), 'finished_by_user_id' => $p['user']->id]));
    expect(DB::table('production_batches')->count())->toBe(1);
});

it('sets the confirmation columns exactly when confirmed, by someone other than the finisher unless it was the same write', function () {
    $p = prodParents();
    $finished = ['produced_qty' => '12.0000', 'waste_qty' => '0.0000', 'finished_at' => '2026-10-23 06:00:00', 'finished_by_user_id' => $p['user']->id];
    $row = fn (array $over): array => rawBatch($p, DB::table('production_requests')->insertGetId(rawRequest($p)), $over + $finished);
    $by = fn (User $user, string $at): array => ['status' => 'confirmed', 'confirmed_at' => $at, 'confirmed_by_user_id' => $user->id];

    // confirmed without who or when; a finished one that already names a confirmer; a started one with confirmation columns
    expect(fn () => DB::table('production_batches')->insert($row(['status' => 'confirmed'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert($row(['status' => 'confirmed', 'confirmed_at' => '2026-10-23 07:00:00'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert($row(['status' => 'finished', 'confirmed_by_user_id' => $p['other']->id])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('production_batches')->insert($row(['status' => 'started', 'confirmed_at' => '2026-10-23 07:00:00', 'confirmed_by_user_id' => $p['other']->id])))->toThrow(QueryException::class)
        // the overseer is not the person who pressed Done, later: refused; at the very instant of finishing (a station with no overseer): allowed
        ->and(fn () => DB::table('production_batches')->insert($row($by($p['user'], '2026-10-23 07:00:00'))))->toThrow(QueryException::class);

    DB::table('production_batches')->insert($row($by($p['other'], '2026-10-23 07:00:00')));
    DB::table('production_batches')->insert($row($by($p['user'], '2026-10-23 06:00:00')));
    DB::table('production_batches')->insert($row(['status' => 'finished']));
    expect(DB::table('production_batches')->count())->toBe(3);
});

it('adds the advance-order deposit to orders as DECIMAL(12,2) defaulting to zero and never negative', function () {
    $c = DB::selectOne(
        'select numeric_precision as p, numeric_scale as s, is_nullable as nullable, column_default as dflt
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), 'orders', 'deposit_amount'],
    );

    expect($c)->not->toBeNull()
        ->and([(int) $c->p, (int) $c->s])->toBe([12, 2])
        ->and(strtoupper((string) $c->nullable))->toBe('NO')
        ->and((float) trim((string) $c->dflt, "'"))->toBe(0.0);
});
