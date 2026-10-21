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
use Illuminate\Support\Str;

/*
 * Step 2.3 (phase-2 plan section 3, migration 8): the transfers table asked of the database itself. A transfer asks for
 * stock to be moved between two locations of the branch; it is a request until someone at the source decides, and the two
 * movements exist only after a confirm. branch_id NOT NULL with a foreign key, the unique key that makes a replay find the
 * same row, the CHECKs behind the state machine, the quantity and the two ends.
 */

/** @return array{branch: Branch, user: User, from: int, to: int, item: int} the rows a transfer hangs on */
function transferParents(): array
{
    $branch = Branch::factory()->create();

    return [
        'branch' => $branch,
        'user' => User::factory()->create(['branch_id' => $branch->id]),
        'from' => Location::factory()->ofType(LocationType::Store)->create(['branch_id' => $branch->id])->id,
        'to' => Location::factory()->ofType(LocationType::Kitchen)->create(['branch_id' => $branch->id])->id,
        'item' => Item::factory()->ingredient('g')->create(['branch_id' => $branch->id])->id,
    ];
}

/**
 * @param  array<string, mixed>  $p
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function rawTransfer(array $p, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'client_uuid' => (string) Str::uuid(), 'item_id' => $p['item'],
        'from_location_id' => $p['from'], 'to_location_id' => $p['to'], 'qty' => '5000.0000', 'status' => 'requested',
        'note' => null, 'reject_reason' => null, 'production_request_id' => null,
        'requested_by_user_id' => $p['user']->id, 'requested_at' => now(), 'decided_by_user_id' => null, 'decided_at' => null,
        'out_movement_id' => null, 'in_movement_id' => null,
        'created_by_user_id' => $p['user']->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];
}

it('creates the transfers table with branch_id NOT NULL and a foreign key to branches', function () {
    expect(Schema::hasTable('transfers'))->toBeTrue();
    $column = DB::selectOne(
        'select is_nullable as nullable from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), 'transfers', 'branch_id'],
    );
    expect(strtoupper((string) $column?->nullable))->toBe('NO');

    $fk = DB::selectOne(
        "select 1 as ok from information_schema.key_column_usage
          where table_schema = ? and table_name = 'transfers' and column_name = 'branch_id' and referenced_table_name = 'branches'",
        [dbSchema()],
    );
    expect($fk)->not->toBeNull();
})->group('module:inventory', 'feature');

it('stores the quantity as DECIMAL(14,4), never soft-deletes, and stamps who did it', function () {
    $c = DB::selectOne(
        'select numeric_precision as p, numeric_scale as s from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), 'transfers', 'qty'],
    );
    expect([(int) $c?->p, (int) $c?->s])->toBe([14, 4])
        ->and(Schema::hasColumn('transfers', 'deleted_at'))->toBeFalse('a transfer is decided by status')
        ->and(Schema::hasColumns('transfers', [
            'client_uuid', 'item_id', 'from_location_id', 'to_location_id', 'status', 'note', 'reject_reason', 'production_request_id',
            'requested_by_user_id', 'requested_at', 'decided_by_user_id', 'decided_at', 'out_movement_id', 'in_movement_id',
            'created_by_user_id', 'acting_user_id', 'shift_instance_id', 'actor_station_id', 'device_id', 'version',
        ]))->toBeTrue();
})->group('module:inventory', 'feature');

it('keeps a client_uuid once per branch', function () {
    $p = transferParents();
    $uuid = (string) Str::uuid();
    DB::table('transfers')->insert(rawTransfer($p, ['client_uuid' => $uuid]));

    expect(fn () => DB::table('transfers')->insert(rawTransfer($p, ['client_uuid' => $uuid])))->toThrow(QueryException::class);

    // another branch may use the same client uuid
    DB::table('transfers')->insert(rawTransfer(transferParents(), ['client_uuid' => $uuid]));
    expect(DB::table('transfers')->where('client_uuid', $uuid)->count())->toBe(2);
})->group('module:inventory', 'feature');

it('keeps the quantity positive and the two ends different', function () {
    $p = transferParents();

    expect(fn () => DB::table('transfers')->insert(rawTransfer($p, ['qty' => '0.0000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('transfers')->insert(rawTransfer($p, ['qty' => '-1.0000'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('transfers')->insert(rawTransfer($p, ['to_location_id' => $p['from']])))->toThrow(QueryException::class);

    DB::table('transfers')->insert(rawTransfer($p, ['qty' => '0.0001']));
    expect(DB::table('transfers')->count())->toBe(1);
})->group('module:inventory', 'feature');

it('keeps the status to the machine list and the decision columns set exactly when it is decided', function () {
    $p = transferParents();
    $decided = ['decided_by_user_id' => $p['user']->id, 'decided_at' => now()];

    // not a status; decided without who and when; decided without who; requested but decided
    expect(fn () => DB::table('transfers')->insert(rawTransfer($p, ['status' => 'cancelled'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('transfers')->insert(rawTransfer($p, ['status' => 'confirmed'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('transfers')->insert(rawTransfer($p, ['status' => 'rejected', 'decided_at' => now()])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('transfers')->insert(rawTransfer($p, ['status' => 'requested'] + $decided)))->toThrow(QueryException::class);

    DB::table('transfers')->insert(rawTransfer($p, ['status' => 'confirmed'] + $decided));
    DB::table('transfers')->insert(rawTransfer($p, ['status' => 'rejected', 'reject_reason' => 'No flour left'] + $decided));
    DB::table('transfers')->insert(rawTransfer($p));
    expect(DB::table('transfers')->count())->toBe(3);
})->group('module:inventory', 'feature');

it('refuses a location, item or user that does not exist', function () {
    $p = transferParents();

    expect(fn () => DB::table('transfers')->insert(rawTransfer($p, ['from_location_id' => 999999])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('transfers')->insert(rawTransfer($p, ['item_id' => 999999])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('transfers')->insert(rawTransfer($p, ['requested_by_user_id' => 999999])))->toThrow(QueryException::class);
})->group('module:inventory', 'feature');
