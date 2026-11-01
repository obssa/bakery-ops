<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Device;
use App\Shared\Tenancy\CurrentBranch;
use Database\Seeders\DemoSeeder;
use Database\Seeders\Phase0Seeder;
use Database\Seeders\Phase4Seeder;
use Database\Seeders\Phase5Seeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

pest()->group('feature', 'seeds');

/*
 * Phase 5 of the demo bakery (phase-5 plan 8). DemoSeeder composes it after Phase4Seeder when SEED_PHASE is 5 or unset. Since step
 * 5.5d it does one thing: with LOAD_DEVICES=N it enrols load-01 to load-N in the demo branch for the perf-load CI job (34 pads, 12 KDS, 8
 * tills and 6 admin and shift screens for N=60, the order of tests/Fixtures/perf/poll-profile.json). Without LOAD_DEVICES it writes
 * nothing, so `bakery:seed-demo` on the live site adds nothing (and the live host is never seeded by a deploy). The demo branch's pad,
 * KDS, till and bridge come in step 5.6b. It never writes a stock movement, an order or a payment, so no earlier gate's figures move.
 */

afterEach(function () {
    putenv('LOAD_DEVICES');
});

/** @return list<string> every SQL write the callback makes */
function phase5Writes(callable $run): array
{
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });
    $run();

    return $writes;
}

it('composes Phase 5 after Phase 4', function () {
    $seeders = DemoSeeder::seedersUpTo(5);

    expect(end($seeders))->toBe(Phase5Seeder::class)
        ->and($seeders[count($seeders) - 2])->toBe(Phase4Seeder::class)
        ->and(DemoSeeder::seedersUpTo(4))->not->toContain(Phase5Seeder::class);
});

it('writes nothing without LOAD_DEVICES', function () {
    expect(phase5Writes(fn () => (new Phase5Seeder)->run()))->toBe([]);
});

it('lists the kinds of the load devices in the order of the load mix', function () {
    expect(Phase5Seeder::loadDeviceKinds(60))->toHaveCount(60)
        ->and(array_count_values(Phase5Seeder::loadDeviceKinds(60)))->toBe(['tablet' => 40, 'kds' => 12, 'till' => 8])
        ->and(Phase5Seeder::loadDeviceKinds(3))->toBe(['tablet', 'tablet', 'tablet'])
        ->and(array_slice(Phase5Seeder::loadDeviceKinds(60), 33, 3))->toBe(['tablet', 'kds', 'kds'])
        ->and(array_slice(Phase5Seeder::loadDeviceKinds(60), 45, 3))->toBe(['kds', 'till', 'till'])
        ->and(array_slice(Phase5Seeder::loadDeviceKinds(62), 53))->toBe(['till', 'tablet', 'tablet', 'tablet', 'tablet', 'tablet', 'tablet', 'tablet', 'tablet'])
        ->and(Phase5Seeder::loadDeviceKinds(0))->toBe([]);
});

it('enrols load-01 to load-N in the demo branch with LOAD_DEVICES=N, once', function () {
    app(Phase0Seeder::class)->run();
    $branch = Phase0Seeder::existingBranch();
    $before = Device::query()->withoutGlobalScopes()->count();
    putenv('LOAD_DEVICES=36');

    (new Phase5Seeder)->run();

    $devices = Device::query()->withoutGlobalScopes()->where('fingerprint', 'like', 'load-%')->orderBy('fingerprint')->get();
    expect($devices)->toHaveCount(36)
        ->and(Device::query()->withoutGlobalScopes()->count())->toBe($before + 36)
        ->and($devices->first()->fingerprint)->toBe('load-01')
        ->and($devices->last()->fingerprint)->toBe('load-36')
        ->and($devices->pluck('branch_id')->unique()->all())->toBe([$branch->id])
        ->and($devices->map(fn (Device $d): string => $d->kind->value)->all())->toBe(Phase5Seeder::loadDeviceKinds(36))
        ->and($devices->whereNotNull('revoked_at'))->toBeEmpty()
        ->and($devices->whereNotNull('token_hash'))->toBeEmpty()
        ->and(DB::table('audit_log')->where('action', 'device.enrol')->where('subject_id', $devices->first()->id)->exists())->toBeTrue();

    // a second run finds them and writes nothing; the stock, the orders and the payments are never touched
    app(CurrentBranch::class)->clear();
    expect(phase5Writes(fn () => (new Phase5Seeder)->run()))->toBe([])
        ->and(Device::query()->withoutGlobalScopes()->where('fingerprint', 'like', 'load-%')->count())->toBe(36);
});

it('refuses a LOAD_DEVICES that is not a count from 1 to 99', function (string $value) {
    putenv("LOAD_DEVICES={$value}");

    expect(fn () => (new Phase5Seeder)->run())->toThrow(InvalidArgumentException::class, 'LOAD_DEVICES');
})->with(['0', '-1', '100', 'sixty', '6.5']);
