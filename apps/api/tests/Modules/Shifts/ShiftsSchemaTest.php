<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

pest()->group('module:shifts', 'feature');

/*
 * Step 3.2a (phase-3 plan section 3, migrations 3 to 6): shift_templates, shift_template_stations, shift_instances and
 * shift_roster asked of the database itself. branch_id NOT NULL with a foreign key, the CHECKs behind the enums, the three
 * unique keys of shift_instances (a second running shift at one station is refused by the database; a second row of one slot
 * and sequence is refused; a replayed client_uuid is refused), the roster's keys and the template window rules.
 */

function shiftColumn(string $table, string $column): ?object
{
    return DB::selectOne(
        'select data_type as data_type, numeric_precision as p, numeric_scale as s, is_nullable as nullable
           from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), $table, $column],
    );
}

/** @return array{branch: Branch, user: User, other: User, station: int, second: int} */
function shiftParents(): array
{
    // a refusal proves nothing while a table is missing: every test below starts by asserting the tables exist
    foreach (['shift_templates', 'shift_template_stations', 'shift_instances', 'shift_roster'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} exists");
    }

    $branch = Branch::factory()->create();

    return [
        'branch' => $branch,
        'user' => User::factory()->create(['branch_id' => $branch->id]),
        'other' => User::factory()->create(['branch_id' => $branch->id]),
        'station' => Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $branch->id])->id,
        'second' => Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $branch->id])->id,
    ];
}

/**
 * @param  array<string, mixed>  $p
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function rawTemplate(array $p, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'name_en' => 'Day', 'name_am' => 'ቀን', 'start_time' => '06:00:00', 'end_time' => '14:00:00',
        'days_mask' => 127, 'is_active' => 1, 'sort' => 0, 'created_by_user_id' => $p['user']->id, 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ];
}

/**
 * @param  array<string, mixed>  $p
 * @param  array<string, mixed>  $over
 * @return array<string, mixed> a scheduled instance of an ad-hoc slot
 */
function rawShift(array $p, array $over = []): array
{
    return $over + [
        'branch_id' => $p['branch']->id, 'client_uuid' => (string) Str::uuid(), 'station_id' => $p['station'], 'template_id' => null,
        'slot_key' => 0, 'sequence' => 1, 'date' => '2026-10-23', 'starts_at' => null, 'ends_at' => null, 'origin' => 'started',
        'status' => 'scheduled', 'open_key' => null, 'opening_mode' => null,
        'created_by_user_id' => $p['user']->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];
}

/** @param array<string, mixed> $p */
function runningShift(array $p, array $over = []): array
{
    return rawShift($p, $over + ['status' => 'open', 'open_key' => 1, 'opening_mode' => 'carried', 'opened_at' => now(), 'opened_by_user_id' => $p['user']->id]);
}

it('creates the four tables with branch_id NOT NULL and a foreign key to branches', function () {
    foreach (['shift_templates', 'shift_template_stations', 'shift_instances', 'shift_roster'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} exists");
        expect(strtoupper((string) shiftColumn($table, 'branch_id')?->nullable))->toBe('NO');
        $fk = DB::selectOne(
            "select 1 as ok from information_schema.key_column_usage
              where table_schema = ? and table_name = ? and column_name = 'branch_id' and referenced_table_name = 'branches'",
            [dbSchema(), $table],
        );
        expect($fk)->not->toBeNull("{$table}.branch_id has a foreign key");
    }
});

it('soft-deletes templates, their station pairs and scheduled roster rows, and never a shift instance', function () {
    foreach (['shift_templates', 'shift_template_stations', 'shift_roster'] as $table) {
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue("{$table} soft-deletes");
    }
    expect(Schema::hasColumn('shift_instances', 'deleted_at'))->toBeFalse('a shift ends by status')
        ->and(Schema::hasColumns('shift_instances', ['client_uuid', 'created_by_user_id', 'acting_user_id', 'actor_station_id', 'device_id', 'version']))->toBeTrue()
        ->and(Schema::hasColumn('shift_instances', 'shift_instance_id'))->toBeFalse('the row is the shift')
        ->and(Schema::hasColumns('shift_templates', ['created_by_user_id', 'acting_user_id', 'shift_instance_id', 'actor_station_id', 'device_id', 'version']))->toBeTrue()
        ->and(Schema::hasColumn('shift_roster', 'shift_instance_id'))->toBeTrue();
});

it('stores the template window as TIME in local wall-clock and the date of an instance as a DATE', function () {
    expect(shiftColumn('shift_templates', 'start_time')?->data_type)->toBe('time')
        ->and(shiftColumn('shift_templates', 'end_time')?->data_type)->toBe('time')
        ->and(shiftColumn('shift_instances', 'date')?->data_type)->toBe('date')
        ->and(strtoupper((string) shiftColumn('shift_instances', 'date')?->nullable))->toBe('NO')
        ->and(strtoupper((string) shiftColumn('shift_instances', 'starts_at')?->nullable))->toBe('YES')
        ->and(strtoupper((string) shiftColumn('shift_instances', 'open_key')?->nullable))->toBe('YES', 'NULL once the shift is not running');
});

it('accepts a day template and a cross-midnight night template and refuses equal times and a days_mask outside 1 to 127', function () {
    $p = shiftParents();
    DB::table('shift_templates')->insert(rawTemplate($p));
    DB::table('shift_templates')->insert(rawTemplate($p, ['name_en' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00']));
    DB::table('shift_templates')->insert(rawTemplate($p, ['name_en' => 'Until midnight', 'start_time' => '18:00:00', 'end_time' => '00:00:00', 'days_mask' => 1]));

    expect(fn () => DB::table('shift_templates')->insert(rawTemplate($p, ['start_time' => '08:00:00', 'end_time' => '08:00:00'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_templates')->insert(rawTemplate($p, ['start_time' => '00:00:00', 'end_time' => '00:00:00'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_templates')->insert(rawTemplate($p, ['days_mask' => 0])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_templates')->insert(rawTemplate($p, ['days_mask' => 128])))->toThrow(QueryException::class);

    expect(DB::table('shift_templates')->count())->toBe(3);
});

it('keeps one pair of template and station, even among soft-deleted rows', function () {
    $p = shiftParents();
    $template = DB::table('shift_templates')->insertGetId(rawTemplate($p));
    $pair = ['branch_id' => $p['branch']->id, 'template_id' => $template, 'station_id' => $p['station'], 'created_at' => now(), 'updated_at' => now()];
    DB::table('shift_template_stations')->insert($pair);

    expect(fn () => DB::table('shift_template_stations')->insert($pair))->toThrow(QueryException::class, '', 'a removed pair is restored, not duplicated')
        ->and(fn () => DB::table('shift_template_stations')->insert([...$pair, 'template_id' => 999999, 'station_id' => $p['second']]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_template_stations')->insert([...$pair, 'station_id' => 999999]))->toThrow(QueryException::class);

    DB::table('shift_template_stations')->insert([...$pair, 'station_id' => $p['second']]);
    expect(DB::table('shift_template_stations')->count())->toBe(2);
});

it('keeps the shift status, origin and opening mode to the enum lists', function () {
    $p = shiftParents();
    $p['station'] = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $p['branch']->id])->id;

    expect(fn () => DB::table('shift_instances')->insert(rawShift($p, ['status' => 'closed'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_instances')->insert(rawShift($p, ['origin' => 'walk_in'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_instances')->insert(runningShift($p, ['opening_mode' => 'guessed'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_instances')->insert(rawShift($p, ['sequence' => 0])))->toThrow(QueryException::class);

    foreach (['scheduled', 'started', 'call_in'] as $n => $origin) {
        DB::table('shift_instances')->insert(rawShift($p, ['origin' => $origin, 'date' => '2026-11-0'.($n + 1)]));
    }
    foreach (['counted', 'carried'] as $n => $mode) {
        DB::table('shift_instances')->insert(rawShift($p, ['status' => 'pending_approval', 'opening_mode' => $mode, 'opened_at' => now(), 'date' => '2026-12-0'.($n + 1)]));
    }
    expect(DB::table('shift_instances')->count())->toBe(5);
});

it('keeps open_key in step with the status: set while opening, open or counting, NULL otherwise', function () {
    $p = shiftParents();
    $at = ['opened_at' => now(), 'opened_by_user_id' => $p['user']->id];
    $day = 0;
    $next = function (array $row) use (&$day): array {
        $day++;

        return $row + ['date' => sprintf('2027-01-%02d', $day)];
    };

    foreach (['opening' => [], 'open' => $at, 'counting' => $at] as $status => $extra) {
        // the running statuses need the key, and the key means a running status
        expect(fn () => DB::table('shift_instances')->insert($next(rawShift($p, ['status' => $status, 'open_key' => null] + $extra))))
            ->toThrow(QueryException::class, '', "{$status} without open_key");
    }
    foreach (['scheduled', 'cancelled'] as $status) {
        expect(fn () => DB::table('shift_instances')->insert($next(rawShift($p, ['status' => $status, 'open_key' => 1, 'cancelled_at' => $status === 'cancelled' ? now() : null]))))
            ->toThrow(QueryException::class, '', "{$status} with open_key");
    }
    foreach (['pending_approval', 'locked'] as $status) {
        expect(fn () => DB::table('shift_instances')->insert($next(rawShift($p, ['status' => $status, 'open_key' => 1, 'opened_at' => now(), 'locked_at' => $status === 'locked' ? now() : null]))))
            ->toThrow(QueryException::class, '', "{$status} with open_key");
    }
    expect(fn () => DB::table('shift_instances')->insert($next(runningShift($p, ['open_key' => 2]))))->toThrow(QueryException::class, '', 'only 1 means running');

    foreach (['opening', 'open', 'counting'] as $status) {
        DB::table('shift_instances')->insert($next(rawShift($p, ['status' => $status, 'open_key' => 1, 'opened_at' => $status === 'opening' ? null : now(), 'station_id' => Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $p['branch']->id])->id])));
    }
    expect(DB::table('shift_instances')->count())->toBe(3);
});

it('keeps locked_at, cancelled_at and opened_at in step with the status', function () {
    $p = shiftParents();
    $d = 0;
    $row = function (array $over) use ($p, &$d): array {
        $d++;

        return rawShift($p, $over + ['date' => sprintf('2027-02-%02d', $d)]);
    };

    expect(fn () => DB::table('shift_instances')->insert($row(['status' => 'locked', 'opened_at' => now()])))->toThrow(QueryException::class, '', 'locked without locked_at')
        ->and(fn () => DB::table('shift_instances')->insert($row(['status' => 'scheduled', 'locked_at' => now()])))->toThrow(QueryException::class, '', 'locked_at on a scheduled shift')
        ->and(fn () => DB::table('shift_instances')->insert($row(['status' => 'cancelled'])))->toThrow(QueryException::class, '', 'cancelled without cancelled_at')
        ->and(fn () => DB::table('shift_instances')->insert($row(['status' => 'scheduled', 'cancelled_at' => now()])))->toThrow(QueryException::class, '', 'cancelled_at on a scheduled shift')
        ->and(fn () => DB::table('shift_instances')->insert($row(['status' => 'pending_approval'])))->toThrow(QueryException::class, '', 'pending_approval without opened_at')
        ->and(fn () => DB::table('shift_instances')->insert($row(['status' => 'open', 'open_key' => 1])))->toThrow(QueryException::class, '', 'open without opened_at');

    DB::table('shift_instances')->insert($row(['status' => 'locked', 'opened_at' => now(), 'locked_at' => now()]));
    DB::table('shift_instances')->insert($row(['status' => 'cancelled', 'cancelled_at' => now()]));
    DB::table('shift_instances')->insert($row(['status' => 'pending_approval', 'opened_at' => now()]));
    expect(DB::table('shift_instances')->count())->toBe(3);
});

it('lets the database refuse a second running shift at one station, and allows one at another station', function () {
    $p = shiftParents();
    expect(Schema::hasTable('shift_instances'))->toBeTrue();
    DB::table('shift_instances')->insert(runningShift($p));

    // two people pressing Start at once: the unique key (station_id, open_key) decides, not a SELECT before it
    expect(fn () => DB::table('shift_instances')->insert(runningShift($p, ['date' => '2026-10-24'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_instances')->insert(rawShift($p, ['status' => 'opening', 'open_key' => 1, 'date' => '2026-10-25'])))->toThrow(QueryException::class)
        ->and(DB::table('shift_instances')->count())->toBe(1);

    // not running (open_key NULL): any number of finished, waiting and cancelled shifts stack beside the running one
    DB::table('shift_instances')->insert(rawShift($p, ['status' => 'locked', 'opened_at' => now(), 'locked_at' => now(), 'date' => '2026-10-22']));
    DB::table('shift_instances')->insert(rawShift($p, ['status' => 'locked', 'opened_at' => now(), 'locked_at' => now(), 'date' => '2026-10-21']));
    DB::table('shift_instances')->insert(rawShift($p, ['status' => 'scheduled', 'date' => '2026-10-26']));
    DB::table('shift_instances')->insert(rawShift($p, ['status' => 'cancelled', 'cancelled_at' => now(), 'date' => '2026-10-27']));
    DB::table('shift_instances')->insert(rawShift($p, ['status' => 'pending_approval', 'opened_at' => now(), 'date' => '2026-10-20']));
    // another station has its own running shift
    DB::table('shift_instances')->insert(runningShift($p, ['station_id' => $p['second']]));

    expect(DB::table('shift_instances')->where('open_key', 1)->count())->toBe(2)
        ->and(DB::table('shift_instances')->count())->toBe(7);
});

it('lets the database refuse a second row of one slot and sequence on one date at one station', function () {
    $p = shiftParents();
    $template = DB::table('shift_templates')->insertGetId(rawTemplate($p));
    $slot = ['template_id' => $template, 'slot_key' => $template, 'origin' => 'scheduled'];
    DB::table('shift_instances')->insert(rawShift($p, $slot));

    expect(fn () => DB::table('shift_instances')->insert(rawShift($p, $slot)))->toThrow(QueryException::class, '', 'same slot, date and sequence')
        ->and(fn () => DB::table('shift_instances')->insert(rawShift($p, ['slot_key' => 0])))->not->toThrow(QueryException::class);

    // the 2nd start of the same slot on the same date is sequence 2; another date, another station and another template are free
    DB::table('shift_instances')->insert(rawShift($p, $slot + ['sequence' => 2]));
    DB::table('shift_instances')->insert(rawShift($p, $slot + ['date' => '2026-10-24']));
    DB::table('shift_instances')->insert(rawShift($p, $slot + ['station_id' => $p['second']]));
    $other = DB::table('shift_templates')->insertGetId(rawTemplate($p, ['name_en' => 'Night']));
    DB::table('shift_instances')->insert(rawShift($p, ['template_id' => $other, 'slot_key' => $other, 'origin' => 'scheduled']));

    // two ad-hoc shifts one date apart in sequence are free; the same sequence is not
    expect(fn () => DB::table('shift_instances')->insert(rawShift($p, ['slot_key' => 0])))->toThrow(QueryException::class)
        ->and(DB::table('shift_instances')->count())->toBe(6);
});

it('keeps slot_key equal to the template id, or 0 for an ad-hoc shift', function () {
    $p = shiftParents();
    $template = DB::table('shift_templates')->insertGetId(rawTemplate($p));

    expect(fn () => DB::table('shift_instances')->insert(rawShift($p, ['template_id' => $template, 'slot_key' => 0])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_instances')->insert(rawShift($p, ['template_id' => null, 'slot_key' => $template])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_instances')->insert(rawShift($p, ['template_id' => 999999, 'slot_key' => 999999])))->toThrow(QueryException::class, '', 'the template must exist');

    DB::table('shift_instances')->insert(rawShift($p, ['template_id' => $template, 'slot_key' => $template]));
    expect(DB::table('shift_instances')->count())->toBe(1);
});

it('keeps a client_uuid once per branch and refuses an unknown station or person', function () {
    $p = shiftParents();
    $uuid = (string) Str::uuid();
    DB::table('shift_instances')->insert(rawShift($p, ['client_uuid' => $uuid]));

    expect(fn () => DB::table('shift_instances')->insert(rawShift($p, ['client_uuid' => $uuid, 'date' => '2026-10-24'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_instances')->insert(rawShift($p, ['station_id' => 999999, 'date' => '2026-10-24'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_instances')->insert(rawShift($p, ['created_by_user_id' => 999999, 'date' => '2026-10-24'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_instances')->insert(rawShift($p, ['started_by_user_id' => 999999, 'date' => '2026-10-24'])))->toThrow(QueryException::class);

    $elsewhere = shiftParents();
    DB::table('shift_instances')->insert(rawShift($elsewhere, ['client_uuid' => $uuid]));
    expect(DB::table('shift_instances')->where('client_uuid', $uuid)->count())->toBe(2);
});

it('keeps one roster row per person and shift, and left_at after joined_at', function () {
    $p = shiftParents();
    $shift = DB::table('shift_instances')->insertGetId(runningShift($p));
    $row = fn (array $over = []): array => $over + [
        'branch_id' => $p['branch']->id, 'shift_instance_id' => $shift, 'user_id' => $p['user']->id, 'source' => 'started',
        'joined_at' => now(), 'left_at' => null, 'created_by_user_id' => $p['user']->id, 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('shift_roster')->insert($row());

    expect(fn () => DB::table('shift_roster')->insert($row()))->toThrow(QueryException::class, '', 'one row per person and shift')
        ->and(fn () => DB::table('shift_roster')->insert($row(['user_id' => $p['other']->id, 'source' => 'invited'])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_roster')->insert($row(['user_id' => $p['other']->id, 'joined_at' => null, 'left_at' => now()])))->toThrow(QueryException::class, '', 'left without joining')
        ->and(fn () => DB::table('shift_roster')->insert($row(['user_id' => $p['other']->id, 'joined_at' => now(), 'left_at' => now()->subHour()])))->toThrow(QueryException::class, '', 'left before joined')
        ->and(fn () => DB::table('shift_roster')->insert($row(['shift_instance_id' => 999999, 'user_id' => $p['other']->id])))->toThrow(QueryException::class)
        ->and(fn () => DB::table('shift_roster')->insert($row(['user_id' => 999999])))->toThrow(QueryException::class);

    // scheduled (not joined), joined and left at the same instant, and joined then left are all accepted
    DB::table('shift_roster')->insert($row(['user_id' => $p['other']->id, 'source' => 'scheduled', 'joined_at' => null]));
    $third = User::factory()->create(['branch_id' => $p['branch']->id]);
    $fourth = User::factory()->create(['branch_id' => $p['branch']->id]);
    $now = now();
    DB::table('shift_roster')->insert($row(['user_id' => $third->id, 'source' => 'joined', 'joined_at' => $now, 'left_at' => $now]));
    DB::table('shift_roster')->insert($row(['user_id' => $fourth->id, 'source' => 'call_in', 'joined_at' => $now->copy()->subHour(), 'left_at' => $now]));

    expect(DB::table('shift_roster')->count())->toBe(4);
});
