<?php

declare(strict_types=1);

use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'invariants');

/*
 * Migration 14 (phase-3 plan section 3): handover_notes, what the outgoing shift leaves for the incoming one. Append-only by trigger (a
 * correction is a new note), free text in the writer's own language (no _en/_am pair, like an order's notes), a body of 1 to 1000
 * characters, one row per (branch, client_uuid) so an offline replay cannot write twice, and the two indexes the polled feed reads.
 */

const NOTE_COLUMNS = [
    'id', 'branch_id', 'client_uuid', 'shift_instance_id', 'from_user_id', 'to_user_id', 'body',
    'created_by_user_id', 'acting_user_id', 'actor_station_id', 'device_id', 'created_at',
];

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->row = fn (array $over = []): array => $over + [
        'branch_id' => $this->branch->id,
        'client_uuid' => (string) Str::uuid(),
        'shift_instance_id' => $this->shift->id,
        'from_user_id' => $this->f->chef->id,
        'to_user_id' => null,
        'body' => 'Walk-in fridge door sticks',
        'created_by_user_id' => $this->f->chef->id,
        'created_at' => '2026-11-02 08:00:00.123456',
    ];
});

it('has the table with exactly the columns of the plan', function () {
    expect(Schema::hasTable('handover_notes'))->toBeTrue()
        ->and(Schema::getColumnListing('handover_notes'))->toEqualCanonicalizing(NOTE_COLUMNS);
});

it('keeps the microseconds of created_at and has no updated_at or deleted_at: a note is written once', function () {
    $id = DB::table('handover_notes')->insertGetId(($this->row)());

    expect((string) DB::table('handover_notes')->where('id', $id)->value('created_at'))->toBe('2026-11-02 08:00:00.123456')
        ->and(Schema::hasColumns('handover_notes', ['updated_at']))->toBeFalse()
        ->and(Schema::hasColumns('handover_notes', ['deleted_at']))->toBeFalse();
});

it('refuses an UPDATE and a DELETE of a note at the database level', function () {
    $id = DB::table('handover_notes')->insertGetId(($this->row)());

    expect(fn () => DB::transaction(fn () => DB::table('handover_notes')->where('id', $id)->update(['body' => 'Rewritten'])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('handover_notes')->where('id', $id)->delete()))->toThrow(QueryException::class, 'append-only')
        ->and(DB::table('handover_notes')->where('id', $id)->value('body'))->toBe('Walk-in fridge door sticks');
});

it('has the two append-only triggers on MariaDB and MySQL', function () {
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('the trigger names are the MariaDB pattern; PostgreSQL has one forbid_update_delete trigger');
    }
    $names = array_map(fn ($t) => (string) $t->name, DB::select('select trigger_name as name from information_schema.triggers where trigger_schema = ? and event_object_table = ?', [dbSchema(), 'handover_notes']));

    expect($names)->toEqualCanonicalizing(['handover_notes_forbid_update_delete_update', 'handover_notes_forbid_update_delete_delete']);
});

it('takes a body of 1 to 1000 characters and refuses an empty one or a longer one', function () {
    expect(DB::table('handover_notes')->insert(($this->row)(['body' => 'x'])))->toBeTrue()
        ->and(DB::table('handover_notes')->insert(($this->row)(['body' => str_repeat('ቀ', 1000)])))->toBeTrue()
        ->and(fn () => DB::table('handover_notes')->insert(($this->row)(['body' => ''])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('handover_notes')->insert(($this->row)(['body' => str_repeat('x', 1001)])))->toThrow(QueryException::class);
});

it('keeps one note per (branch, client_uuid)', function () {
    $uuid = (string) Str::uuid();
    DB::table('handover_notes')->insert(($this->row)(['client_uuid' => $uuid]));

    expect(fn () => DB::table('handover_notes')->insert(($this->row)(['client_uuid' => $uuid, 'body' => 'Again'])))->toThrow(QueryException::class);
});

it('belongs to a real shift, from a real person, to a real person or to nobody', function () {
    expect(fn () => DB::table('handover_notes')->insert(($this->row)(['shift_instance_id' => 999999])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('handover_notes')->insert(($this->row)(['from_user_id' => 999999])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('handover_notes')->insert(($this->row)(['to_user_id' => 999999])))->toThrow(QueryException::class)
        ->and(DB::table('handover_notes')->insert(($this->row)(['to_user_id' => $this->f->manager->id])))->toBeTrue();
});

it('has the indexes the polled feed and the handover panel read', function () {
    $indexes = collect(Schema::getIndexes('handover_notes'))->map(fn (array $i): array => $i['columns'])->all();

    expect($indexes)->toContain(['shift_instance_id', 'id'])
        ->and($indexes)->toContain(['branch_id', 'id'])
        ->and($indexes)->toContain(['branch_id', 'client_uuid']);
});
