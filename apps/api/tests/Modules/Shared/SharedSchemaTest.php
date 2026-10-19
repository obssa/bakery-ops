<?php

declare(strict_types=1);

use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The three Shared tables (domain-model.md): branches is the tenant, stations are the six fixed codes per branch,
 * idempotency_keys is scoped (branch, user, key). What the database itself must refuse is tested here, on the
 * production engine (MySQL 8 in CI; the schema stays portable to PostgreSQL, ADR 0006).
 */

it('gives a branch Addis Ababa, ETB and empty settings by default', function () {
    $branch = Branch::query()->create(['name' => 'Main'])->fresh();

    expect($branch?->timezone)->toBe('Africa/Addis_Ababa')
        ->and($branch?->currency)->toBe('ETB')
        ->and($branch?->settings)->toBe([])
        ->and($branch?->deleted_at)->toBeNull();
})->group('module:shared', 'feature');

it('accepts exactly the six station codes', function () {
    $branch = actInBranch(Branch::factory()->create());

    foreach (StationCode::cases() as $code) {
        Station::query()->create(['code' => $code, 'name' => $code->defaultName()]);
    }

    expect(Station::query()->count())->toBe(6)
        ->and(StationCode::values())->toBe(['till', 'kitchen', 'bar', 'cake_room', 'bakery', 'store']);

    // information_schema.check_constraints exists on MySQL 8.0.16+ and PostgreSQL alike (ADR 0006).
    $constraint = DB::selectOne(
        "select check_clause as def from information_schema.check_constraints where constraint_schema = ? and constraint_name = 'stations_code_check'",
        [dbSchema()],
    );
    expect($constraint)->not->toBeNull('stations_code_check must exist');
    // MySQL reports the clause with escaped, charset-prefixed literals (_utf8mb4\'till\'); PostgreSQL as 'till'::text.
    $clause = str_replace('\\', '', (string) $constraint?->def);
    foreach (StationCode::values() as $value) {
        expect($clause)->toContain("'{$value}'");
    }
    expect(fn () => DB::table('stations')->insert([
        'branch_id' => $branch->id, 'code' => 'garage', 'name_en' => 'Garage', 'name_am' => 'ጋራዥ',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->group('module:shared', 'feature');

it('allows one station per code per branch', function () {
    $branch = Branch::factory()->create();
    Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $branch->id]);

    expect(fn () => Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $branch->id]))
        ->toThrow(QueryException::class);
})->group('module:shared', 'feature');

it('refuses a station without a branch', function () {
    expect(fn () => Station::query()->create(['code' => StationCode::Till, 'name' => StationCode::Till->defaultName()]))
        ->toThrow(QueryException::class);
})->group('module:shared', 'feature');

it('soft-deletes stations and branches', function () {
    $branch = actInBranch(Branch::factory()->create());
    $station = Station::factory()->forCode(StationCode::Bar)->create(['branch_id' => $branch->id]);

    $station->delete();

    expect(Station::query()->count())->toBe(0)
        ->and(Station::withTrashed()->count())->toBe(1)
        ->and(DB::table('stations')->where('id', $station->id)->value('deleted_at'))->not->toBeNull();

    $branch->delete();
    expect(Branch::query()->find($branch->id))->toBeNull()
        ->and(Branch::withTrashed()->find($branch->id))->not->toBeNull();
})->group('module:shared', 'feature');

it('keeps idempotency keys unique per branch, user and key', function () {
    $branch = Branch::factory()->create();
    $row = ['branch_id' => $branch->id, 'user_id' => 1, 'key' => 'k', 'request_hash' => str_repeat('a', 64),
        'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()];

    DB::table('idempotency_keys')->insert($row);
    DB::table('idempotency_keys')->insert(['user_id' => 2] + $row);

    expect(fn () => DB::table('idempotency_keys')->insert($row))->toThrow(QueryException::class);
})->group('module:shared', 'feature');

it('offers a default bilingual name for every station code', function () {
    foreach (StationCode::cases() as $code) {
        expect($code->defaultName()->en)->not->toBeEmpty()
            ->and($code->defaultName()->am)->not->toBeEmpty()
            ->and($code->defaultName()->am)->not->toBe($code->defaultName()->en);
    }
})->group('module:shared', 'unit');
