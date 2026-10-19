<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Shared\Http\ApiError;
use App\Shared\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Identity tables as domain-model.md lists them, asked of the real database (MariaDB in tests and live,
 * portable to PostgreSQL): users carries branch_id and the login/short-code columns, the unique pairs hold, the
 * locale is constrained to en|am, and the temporary users exception in .invariants-allow is gone.
 */

it('extends users with branch_id, phone, short code state, locale, activity, photo and soft deletes', function () {
    expect(Schema::hasColumns('users', [
        'id', 'branch_id', 'name', 'phone', 'email', 'password', 'short_code_hash', 'short_code_attempts',
        'pin_locked_until', 'locale', 'is_active', 'photo_url', 'remember_token', 'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('users', 'email_verified_at'))->toBeFalse();

    $nullable = fn (string $col): string => strtoupper((string) DB::selectOne(
        'select is_nullable as n from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
        [dbSchema(), 'users', $col],
    )->n);
    expect($nullable('branch_id'))->toBe('NO')
        ->and($nullable('phone'))->toBe('NO')
        ->and($nullable('email'))->toBe('YES')
        ->and($nullable('short_code_hash'))->toBe('YES');
})->group('module:identity', 'invariants');

it('keeps phone unique per branch and lets two branches share a phone', function () {
    $a = Branch::factory()->create();
    $b = Branch::factory()->create();
    User::factory()->create(['branch_id' => $a->id, 'phone' => '+251900000001']);
    User::factory()->create(['branch_id' => $b->id, 'phone' => '+251900000001']);

    expect(fn () => User::factory()->create(['branch_id' => $a->id, 'phone' => '+251900000001']))->toThrow(QueryException::class);
})->group('module:identity', 'invariants');

it('constrains locale to en or am at the database level', function () {
    $branch = Branch::factory()->create();

    expect(fn () => DB::table('users')->insert([
        'branch_id' => $branch->id, 'name' => 'x', 'phone' => '+251900000002', 'password' => 'x', 'locale' => 'fr',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->group('module:identity', 'invariants');

it('creates the spatie, assignment, device and acting-session tables with their keys', function () {
    foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'personal_access_tokens',
        'user_station_assignments', 'devices', 'acting_sessions'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }
    expect(Schema::hasColumns('user_station_assignments', ['id', 'branch_id', 'user_id', 'station_id', 'can_count', 'is_overseer', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('devices', ['id', 'branch_id', 'name', 'fingerprint', 'enrolled_by', 'enrolled_at', 'revoked_at', 'deleted_at']))->toBeTrue()
        ->and(Schema::hasColumns('acting_sessions', ['id', 'branch_id', 'user_id', 'device_id', 'started_at', 'expires_at', 'ended_at']))->toBeTrue();

    $unique = fn (string $table): array => array_map(
        fn ($r) => (string) $r->cols,
        DB::select(
            "select group_concat(column_name order by seq_in_index) as cols from information_schema.statistics
              where table_schema = ? and table_name = ? and non_unique = 0 and index_name <> 'PRIMARY' group by index_name",
            [dbSchema(), $table],
        ),
    );
    expect($unique('users'))->toContain('branch_id,phone')
        ->and($unique('user_station_assignments'))->toContain('user_id,station_id')
        ->and($unique('devices'))->toContain('fingerprint');
})->group('module:identity', 'invariants');

it('no longer needs the users exception in .invariants-allow', function () {
    $file = dirname(__DIR__, 3).'/.invariants-allow';
    $tables = array_filter(array_map(fn (string $l) => trim(explode('#', $l, 2)[0]), file($file, FILE_IGNORE_NEW_LINES) ?: []));

    expect($tables)->not->toContain('users');
})->group('module:identity', 'invariants');

it('keeps the en and am identity dictionaries in step, and ApiError reads them', function () {
    $en = Arr::dot(require base_path('lang/en/identity.php'));
    $am = Arr::dot(require base_path('lang/am/identity.php'));

    expect(array_keys($am))->toEqualCanonicalizing(array_keys($en))->and($en)->not->toBeEmpty();
    foreach ($en as $key => $value) {
        expect($value)->toBeString()->not->toBeEmpty("en {$key} is empty")
            ->and($am[$key])->toBeString()->not->toBeEmpty("am {$key} is empty")->not->toBe($value, "am {$key} is still English");
    }

    $body = ApiError::body('identity.login.invalid');
    expect($body['message_en'])->toBe($en['login.invalid'])->and($body['message_am'])->toBe($am['login.invalid']);
})->group('module:identity', 'invariants');
