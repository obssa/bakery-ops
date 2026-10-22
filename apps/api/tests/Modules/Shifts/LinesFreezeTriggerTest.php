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

pest()->group('module:shifts', 'invariants');

/*
 * Migration 10 (phase-3 plan section 3): stock_count_lines is immutable after submit, by trigger. An INSERT is refused when the
 * parent count is not a draft; an UPDATE of any column other than reason_status, decision_note, adjustment_movement_id and
 * updated_at is refused when the parent is not a draft, and even those once it is locked; every DELETE is refused. The
 * trigger text names every column of the table (this test fails when a column is added and the trigger is not updated).
 */

const LINE_DECISION_COLUMNS = ['reason_status', 'decision_note', 'adjustment_movement_id', 'updated_at'];

/** @return array{branch: Branch, user: User, shift: int, location: int, items: list<int>, count: int, line: int} */
function frozenFixture(string $parentStatus = 'draft'): array
{
    foreach (['stock_counts', 'stock_count_lines', 'variance_reasons'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} exists");
    }
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $station = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $branch->id])->id;
    $location = Location::factory()->ofType(LocationType::Kitchen)->create(['branch_id' => $branch->id, 'station_id' => $station])->id;
    $shift = DB::table('shift_instances')->insertGetId([
        'branch_id' => $branch->id, 'client_uuid' => (string) Str::uuid(), 'station_id' => $station, 'slot_key' => 0, 'sequence' => 1, 'date' => '2026-10-23',
        'origin' => 'started', 'status' => 'counting', 'open_key' => 1, 'opening_mode' => 'carried', 'opened_at' => now(), 'opened_by_user_id' => $user->id,
        'created_by_user_id' => $user->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $items = [];
    foreach ([1, 2, 3] as $_) {
        $items[] = Item::factory()->ingredient('g')->create(['branch_id' => $branch->id, 'created_by_user_id' => $user->id])->id;
    }
    $count = DB::table('stock_counts')->insertGetId([
        'branch_id' => $branch->id, 'client_uuid' => (string) Str::uuid(), 'shift_instance_id' => $shift, 'location_id' => $location, 'type' => 'closing',
        'seq' => 1, 'status' => 'draft', 'started_at' => now(), 'created_by_user_id' => $user->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $line = DB::table('stock_count_lines')->insertGetId([
        'branch_id' => $branch->id, 'count_id' => $count, 'item_id' => $items[0], 'expected_qty' => '10.0000', 'carried_qty' => '9.0000', 'counted_qty' => '8.0000',
        'variance_qty' => '-2.0000', 'confirmed_at' => now(), 'variance_reason_id' => null, 'variance_note' => 'spilled', 'reason_status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $fixture = ['branch' => $branch, 'user' => $user, 'shift' => $shift, 'location' => $location, 'items' => $items, 'count' => $count, 'line' => $line];
    if ($parentStatus !== 'draft') {
        setParentStatus($fixture, $parentStatus);
    }

    return $fixture;
}

/** @param array<string, mixed> $f */
function setParentStatus(array $f, string $status): void
{
    $set = ['status' => $status, 'submitted_at' => now()];
    if ($status === 'locked') {
        $set += ['chain_seq' => 1, 'previous_hash' => str_repeat('0', 64), 'payload_hash' => str_repeat('a', 64), 'payload_version' => 1, 'canonical_payload' => '{}', 'locked_at' => now()];
    }
    DB::table('stock_counts')->where('id', $f['count'])->update($set);
}

/** A value for each column that differs from the row's, so an UPDATE of that column alone is a real change. */
function changedValue(array $f, string $column): mixed
{
    return match ($column) {
        'id' => 999999,
        'branch_id' => Branch::factory()->create()->id,
        'count_id' => DB::table('stock_counts')->insertGetId([
            'branch_id' => $f['branch']->id, 'client_uuid' => (string) Str::uuid(), 'shift_instance_id' => $f['shift'], 'location_id' => $f['location'], 'type' => 'opening',
            'seq' => 1, 'status' => 'draft', 'started_at' => now(), 'created_by_user_id' => $f['user']->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]),
        'item_id' => $f['items'][1],
        'expected_qty' => '11.0000',
        'carried_qty' => '12.0000',
        'counted_qty' => '7.0000',
        'variance_qty' => '-3.0000',
        'confirmed_at' => '2020-01-01 00:00:00',
        'variance_reason_id' => (int) DB::table('variance_reasons')->where('branch_id', $f['branch']->id)->orderBy('id')->value('id'),
        'variance_note' => 'changed',
        'photo_key' => 'files/x.jpg',
        'reason_status' => 'accepted',
        'decision_note' => 'fine',
        'adjustment_movement_id' => null,
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2030-01-01 00:00:00',
        default => throw new LogicException("no changed value for stock_count_lines.{$column}: add one"),
    };
}

/** @return list<string> */
function lineColumns(): array
{
    return array_map(
        fn (object $c): string => (string) $c->column_name,
        DB::select('select column_name as column_name from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position', [dbSchema(), 'stock_count_lines']),
    );
}

it('lets a draft count\'s lines be inserted, updated in any column and then submitted', function () {
    $f = frozenFixture();

    expect(DB::table('stock_count_lines')->insert(['branch_id' => $f['branch']->id, 'count_id' => $f['count'], 'item_id' => $f['items'][1], 'created_at' => now(), 'updated_at' => now()]))->toBeTrue();
    expect(DB::table('stock_count_lines')->where('id', $f['line'])->update(['counted_qty' => '7.5000', 'variance_note' => 'recounted', 'expected_qty' => '10.5000']))->toBe(1);
});

it('refuses a new line on a count that is not a draft, whatever its status', function (string $status) {
    $f = frozenFixture($status);

    expect(fn () => DB::table('stock_count_lines')->insert(['branch_id' => $f['branch']->id, 'count_id' => $f['count'], 'item_id' => $f['items'][1], 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class, 'immutable');
})->with(['awaiting_cosign', 'pending_approval', 'approved', 'locked', 'rejected']);

it('refuses to change any column but the decision ones once the count is submitted', function (string $status) {
    $f = frozenFixture($status);
    $frozen = array_values(array_diff(lineColumns(), LINE_DECISION_COLUMNS));
    expect($frozen)->toContain('counted_qty', 'expected_qty', 'variance_qty', 'confirmed_at', 'variance_reason_id', 'variance_note', 'photo_key', 'carried_qty', 'item_id', 'count_id', 'branch_id', 'id');

    foreach ($frozen as $column) {
        $value = changedValue($f, $column);
        expect(fn () => DB::table('stock_count_lines')->where('id', $f['line'])->update([$column => $value]))
            ->toThrow(QueryException::class, 'immutable');
    }
    // nothing moved
    $row = DB::table('stock_count_lines')->where('id', $f['line'])->first();
    expect((string) $row->counted_qty)->toBe('8.0000')->and((string) $row->expected_qty)->toBe('10.0000');
})->with(['awaiting_cosign', 'pending_approval', 'approved', 'rejected']);

it('lets the decision columns change on a submitted count that is not locked, and on nothing once it is locked', function () {
    $f = frozenFixture('pending_approval');
    $movement = null;

    expect(DB::table('stock_count_lines')->where('id', $f['line'])->update(['reason_status' => 'accepted', 'decision_note' => 'seen', 'adjustment_movement_id' => $movement, 'updated_at' => now()->addSecond()]))->toBe(1);

    setParentStatus($f, 'approved');
    expect(DB::table('stock_count_lines')->where('id', $f['line'])->update(['reason_status' => 'rejected', 'decision_note' => 'no']))->toBe(1);

    setParentStatus($f, 'locked');
    foreach (LINE_DECISION_COLUMNS as $column) {
        $value = changedValue($f, $column);
        expect(fn () => DB::table('stock_count_lines')->where('id', $f['line'])->update([$column => $value]))->toThrow(QueryException::class, 'immutable');
    }
    foreach (array_diff(lineColumns(), LINE_DECISION_COLUMNS) as $column) {
        $value = changedValue($f, $column);
        expect(fn () => DB::table('stock_count_lines')->where('id', $f['line'])->update([$column => $value]))->toThrow(QueryException::class, 'immutable');
    }
});

it('refuses every delete, of a draft count\'s line too', function (string $status) {
    $f = frozenFixture($status);

    expect(fn () => DB::table('stock_count_lines')->where('id', $f['line'])->delete())->toThrow(QueryException::class, 'immutable');
    expect(DB::table('stock_count_lines')->where('id', $f['line'])->exists())->toBeTrue();
})->with(['draft', 'pending_approval', 'locked']);

it('names every column of the table in the MariaDB update trigger, so a new column cannot be forgotten', function () {
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('The column list lives in the MariaDB trigger text; PostgreSQL compares to_jsonb(NEW) with to_jsonb(OLD).');
    }
    frozenFixture();

    $triggers = DB::select(
        'select trigger_name as name, action_statement as body from information_schema.triggers where trigger_schema = ? and event_object_table = ?',
        [dbSchema(), 'stock_count_lines'],
    );
    $byName = collect($triggers)->mapWithKeys(fn (object $t): array => [(string) $t->name => (string) $t->body]);
    expect($byName->keys()->sort()->values()->all())->toBe(['stock_count_lines_forbid_delete', 'stock_count_lines_freeze_insert', 'stock_count_lines_freeze_update']);

    $update = $byName['stock_count_lines_freeze_update'];
    foreach (array_diff(lineColumns(), LINE_DECISION_COLUMNS) as $column) {
        expect($update)->toContain("NEW.{$column} <=> OLD.{$column}");
    }
    foreach (LINE_DECISION_COLUMNS as $column) {
        expect($update)->not->toContain("NEW.{$column} <=> OLD.{$column}");
    }
});
