<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Item;
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

pest()->group('module:shifts', 'feature');

/*
 * Step 3.3a (phase-3 plan section 3, migrations 8 to 10): variance_reasons, stock_counts and stock_count_lines asked of the
 * database itself. branch_id NOT NULL with a foreign key, the CHECKs behind the enums, the three unique keys of stock_counts
 * (a replayed client_uuid, a second row of one slot and sequence, a second link of one place at one chain sequence), the lock
 * columns that are all there or all absent, the lines' keys and decimals. Every case starts by asserting the tables exist, so a
 * missing table cannot pass as a refusal.
 */

/** @return array{branch: Branch, user: User, other: User, station: int, shift: int, location: int, item: int, second: int} */
function countParents(): array
{
    foreach (['variance_reasons', 'stock_counts', 'stock_count_lines'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} exists");
    }

    $branch = Branch::factory()->create();
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $station = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $branch->id])->id;
    $location = Location::factory()->ofType(LocationType::Kitchen)->create(['branch_id' => $branch->id, 'station_id' => $station])->id;
    $shift = DB::table('shift_instances')->insertGetId([
        'branch_id' => $branch->id, 'client_uuid' => (string) Str::uuid(), 'station_id' => $station, 'template_id' => null, 'slot_key' => 0,
        'sequence' => 1, 'date' => '2026-10-23', 'origin' => 'started', 'status' => 'counting', 'open_key' => 1, 'opening_mode' => 'carried',
        'opened_at' => now(), 'opened_by_user_id' => $user->id, 'created_by_user_id' => $user->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [
        'branch' => $branch,
        'user' => $user,
        'other' => User::factory()->create(['branch_id' => $branch->id]),
        'station' => $station,
        'shift' => $shift,
        'location' => $location,
        'item' => Item::factory()->ingredient('g')->create(['branch_id' => $branch->id, 'created_by_user_id' => $user->id])->id,
        'second' => Location::factory()->ofType(LocationType::Bar)->create(['branch_id' => $branch->id, 'station_id' => $station])->id,
    ];
}

/**
 * @param  array<string, mixed>  $p
 * @param  array<string, mixed>  $over
 * @return array<string, mixed> a draft closing count
 */
function rawCountRow(array $p, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'client_uuid' => (string) Str::uuid(), 'shift_instance_id' => $p['shift'], 'location_id' => $p['location'],
        'type' => 'closing', 'seq' => 1, 'status' => 'draft', 'started_at' => now(), 'created_by_user_id' => $p['user']->id, 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ];
}

/**
 * @param  array<string, mixed>  $over
 * @return array<string, mixed> a count that is locked: every lock column set
 */
function lockedCountColumns(array $over = []): array
{
    return $over + [
        'status' => 'locked', 'chain_seq' => 1, 'previous_hash' => str_repeat('0', 64), 'payload_hash' => str_repeat('a', 64),
        'payload_version' => 1, 'canonical_payload' => '{}', 'locked_at' => now(),
    ];
}

/**
 * @param  array<string, mixed>  $p
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function rawLineRow(array $p, int $countId, array $over = []): array
{
    return $over + ['branch_id' => $p['branch']->id, 'count_id' => $countId, 'item_id' => $p['item'], 'created_at' => now(), 'updated_at' => now()];
}

function countColumnInfo(string $table, string $column): ?object
{
    return DB::selectOne(
        'select data_type as data_type, numeric_precision as p, numeric_scale as s, is_nullable as nullable
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), $table, $column],
    );
}

it('creates the three tables with branch_id NOT NULL and a foreign key to branches', function () {
    countParents();
    foreach (['variance_reasons', 'stock_counts', 'stock_count_lines'] as $table) {
        expect(strtoupper((string) countColumnInfo($table, 'branch_id')?->nullable))->toBe('NO');
        $fk = DB::selectOne(
            'select referenced_table_name as t from information_schema.key_column_usage
              where table_schema = ? and table_name = ? and column_name = ? and referenced_table_name is not null',
            [dbSchema(), $table, 'branch_id'],
        );
        expect($fk?->t)->toBe('branches');
    }
});

it('stores quantities as DECIMAL(14,4) and leaves the decision and variance columns nullable', function () {
    countParents();
    foreach (['expected_qty', 'carried_qty', 'counted_qty', 'variance_qty'] as $column) {
        $info = countColumnInfo('stock_count_lines', $column);
        expect((int) $info?->p)->toBe(14)->and((int) $info?->s)->toBe(4)->and(strtoupper((string) $info?->nullable))->toBe('YES');
    }
    foreach (['snapshot_at', 'locked_at'] as $column) {
        expect(strtoupper((string) countColumnInfo('stock_counts', $column)?->nullable))->toBe('YES');
    }
});

it('accepts a draft count and refuses a type, a status, a sequence, a decision or a supersede cause outside the lists', function () {
    $p = countParents();
    expect(DB::table('stock_counts')->insert(rawCountRow($p)))->toBeTrue();

    foreach ([
        ['type' => 'midday'],
        ['status' => 'closed'],
        ['seq' => 0],
        ['decision' => 'maybe', 'decided_at' => now()],
        ['supersede_cause' => 'redo'],
    ] as $i => $bad) {
        expect(fn () => DB::table('stock_counts')->insert(rawCountRow($p, $bad + ['seq' => 10 + $i])))->toThrow(QueryException::class);
    }
    foreach (['opening', 'closing'] as $i => $type) {
        expect(DB::table('stock_counts')->insert(rawCountRow($p, ['type' => $type, 'seq' => 20 + $i, 'location_id' => $p['second']])))->toBeTrue();
    }
    foreach (['awaiting_cosign', 'pending_approval', 'approved', 'rejected'] as $i => $status) {
        expect(DB::table('stock_counts')->insert(rawCountRow($p, ['status' => $status, 'seq' => 30 + $i])))->toBeTrue();
    }
    expect(DB::table('stock_counts')->insert(rawCountRow($p, ['seq' => 40, 'supersede_cause' => 'recount'])))->toBeTrue();
});

it('has the lock columns all set exactly when the status is locked', function () {
    $p = countParents();

    expect(DB::table('stock_counts')->insert(rawCountRow($p, lockedCountColumns(['seq' => 1]))))->toBeTrue();

    // locked without a hash, or a hash on a count that is not locked, is refused
    foreach (['payload_hash', 'previous_hash', 'chain_seq', 'canonical_payload', 'locked_at'] as $i => $missing) {
        expect(fn () => DB::table('stock_counts')->insert(rawCountRow($p, lockedCountColumns(['seq' => 2 + $i, 'chain_seq' => 2 + $i, $missing => null]))))
            ->toThrow(QueryException::class);
    }
    expect(fn () => DB::table('stock_counts')->insert(rawCountRow($p, ['seq' => 9, 'payload_hash' => str_repeat('b', 64), 'previous_hash' => str_repeat('0', 64), 'chain_seq' => 9, 'canonical_payload' => '{}', 'locked_at' => now()])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('stock_counts')->insert(rawCountRow($p, lockedCountColumns(['seq' => 10, 'chain_seq' => 0]))))->toThrow(QueryException::class);
});

it('sets a decision exactly when decided_at is set', function () {
    $p = countParents();

    expect(DB::table('stock_counts')->insert(rawCountRow($p, ['seq' => 1, 'status' => 'approved', 'decision' => 'approved', 'decided_at' => now(), 'decided_by_user_id' => $p['other']->id])))->toBeTrue();
    expect(fn () => DB::table('stock_counts')->insert(rawCountRow($p, ['seq' => 2, 'decision' => 'approved'])))->toThrow(QueryException::class);
    expect(fn () => DB::table('stock_counts')->insert(rawCountRow($p, ['seq' => 3, 'decided_at' => now()])))->toThrow(QueryException::class);
});

it('refuses a replayed client_uuid, a second row of one slot and sequence, and a second link at one chain sequence', function () {
    $p = countParents();
    $uuid = (string) Str::uuid();
    DB::table('stock_counts')->insert(rawCountRow($p, ['client_uuid' => $uuid]));

    // the same client uuid in the branch
    expect(fn () => DB::table('stock_counts')->insert(rawCountRow($p, ['client_uuid' => $uuid, 'seq' => 2])))->toThrow(QueryException::class);
    // the same shift, place, type and sequence
    expect(fn () => DB::table('stock_counts')->insert(rawCountRow($p)))->toThrow(QueryException::class);
    // the next sequence, another place and the other type are different slots
    expect(DB::table('stock_counts')->insert(rawCountRow($p, ['seq' => 2])))->toBeTrue()
        ->and(DB::table('stock_counts')->insert(rawCountRow($p, ['location_id' => $p['second']])))->toBeTrue()
        ->and(DB::table('stock_counts')->insert(rawCountRow($p, ['type' => 'opening'])))->toBeTrue();

    // the chain: one locked link per (branch, place, chain_seq); unlocked rows (NULL) never collide
    DB::table('stock_counts')->insert(rawCountRow($p, lockedCountColumns(['type' => 'opening', 'seq' => 5, 'chain_seq' => 7])));
    expect(fn () => DB::table('stock_counts')->insert(rawCountRow($p, lockedCountColumns(['type' => 'opening', 'seq' => 6, 'chain_seq' => 7]))))->toThrow(QueryException::class);
    expect(DB::table('stock_counts')->insert(rawCountRow($p, lockedCountColumns(['type' => 'opening', 'seq' => 7, 'chain_seq' => 8]))))->toBeTrue()
        ->and(DB::table('stock_counts')->insert(rawCountRow($p, lockedCountColumns(['type' => 'opening', 'seq' => 8, 'chain_seq' => 7, 'location_id' => $p['second']]))))->toBeTrue();
    expect(DB::table('stock_counts')->whereNull('chain_seq')->count())->toBeGreaterThan(3);
});

it('keeps superseded_by_id as a foreign key to the same table', function () {
    $p = countParents();
    $first = DB::table('stock_counts')->insertGetId(rawCountRow($p));

    expect(fn () => DB::table('stock_counts')->insert(rawCountRow($p, ['seq' => 2, 'superseded_by_id' => 999999])))->toThrow(QueryException::class);
    $second = DB::table('stock_counts')->insertGetId(rawCountRow($p, ['seq' => 2]));
    expect(DB::table('stock_counts')->where('id', $first)->update(['superseded_by_id' => $second]))->toBe(1);
});

it('keeps one line per item on a count, a counted figure of zero or more, and the reason status list', function () {
    $p = countParents();
    $count = DB::table('stock_counts')->insertGetId(rawCountRow($p));

    expect(DB::table('stock_count_lines')->insert(rawLineRow($p, $count, ['counted_qty' => '0.0000', 'reason_status' => 'none'])))->toBeTrue();
    expect(fn () => DB::table('stock_count_lines')->insert(rawLineRow($p, $count)))->toThrow(QueryException::class);

    $other = Item::factory()->ingredient('g')->create(['branch_id' => $p['branch']->id, 'created_by_user_id' => $p['user']->id])->id;
    expect(fn () => DB::table('stock_count_lines')->insert(rawLineRow($p, $count, ['item_id' => $other, 'counted_qty' => '-0.0001'])))->toThrow(QueryException::class);
    expect(fn () => DB::table('stock_count_lines')->insert(rawLineRow($p, $count, ['item_id' => $other, 'reason_status' => 'maybe'])))->toThrow(QueryException::class);
    foreach (['none', 'pending', 'accepted', 'rejected'] as $i => $status) {
        $item = Item::factory()->ingredient('g')->create(['branch_id' => $p['branch']->id, 'created_by_user_id' => $p['user']->id])->id;
        expect(DB::table('stock_count_lines')->insert(rawLineRow($p, $count, ['item_id' => $item, 'reason_status' => $status])))->toBeTrue();
    }
    // a signed variance and an expected figure of any size the column holds
    $big = Item::factory()->ingredient('g')->create(['branch_id' => $p['branch']->id, 'created_by_user_id' => $p['user']->id])->id;
    expect(DB::table('stock_count_lines')->insert(rawLineRow($p, $count, ['item_id' => $big, 'expected_qty' => '9999999999.9999', 'counted_qty' => '0.0000', 'variance_qty' => '-9999999999.9999'])))->toBeTrue();
});

it('keeps a branch\'s reason codes unique and a code reusable in another branch', function () {
    $p = countParents();
    $row = ['branch_id' => $p['branch']->id, 'code' => 'spilled', 'name_en' => 'Spilled', 'name_am' => 'ፈሰሰ', 'requires_note' => 0, 'is_active' => 1, 'sort' => 9, 'version' => 1, 'created_at' => now(), 'updated_at' => now()];

    expect(DB::table('variance_reasons')->insert($row))->toBeTrue();
    expect(fn () => DB::table('variance_reasons')->insert($row))->toThrow(QueryException::class);
    $other = Branch::factory()->create();
    expect(DB::table('variance_reasons')->insert(['branch_id' => $other->id] + $row))->toBeTrue();
});
