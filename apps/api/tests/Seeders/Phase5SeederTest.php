<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Device;
use App\Modules\Payments\Actions\SaveReceiptSettings;
use App\Modules\Payments\Models\ReceiptSettings;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Database\Seeders\Demo\DemoBakery;
use Database\Seeders\DemoSeeder;
use Database\Seeders\Phase0Seeder;
use Database\Seeders\Phase4Seeder;
use Database\Seeders\Phase5Seeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

pest()->group('feature', 'seeds');

/*
 * Phase 5 of the demo bakery (phase-5 plan 8). DemoSeeder composes it after Phase4Seeder when SEED_PHASE is 5 or unset. In the demo branch
 * it enrols the shop's four kinds of device under fixed fingerprints (demo-pad-1, demo-kds-1 at the kitchen, demo-till-1 at the till,
 * demo-bridge-1 with a random token nobody sees), gives the receipts a made-up TIN and 80 mm paper when they have no TIN, and with
 * LOAD_DEVICES=N it also enrols load-01 to load-N for the perf-load CI job (34 pads, 12 KDS, 8 tills and 6 admin and shift screens for
 * N=60, the order of tests/Fixtures/perf/poll-profile.json). A second run writes nothing. In production it writes nothing at all: the
 * live host is never seeded (10.2 D5). It never writes a stock movement, an order or a payment, so no earlier gate's figures move.
 */

afterEach(function () {
    putenv('LOAD_DEVICES');
    app()->detectEnvironment(fn (): string => 'testing');
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

it('enrols the demo pad, KDS, till and bridge and gives the receipts the sample TIN on 80 mm paper, once', function () {
    app(Phase0Seeder::class)->run();
    $branch = Phase0Seeder::existingBranch();
    $before = Device::query()->withoutGlobalScopes()->count();
    $untouched = fn (): array => [DB::table('stock_movements')->count(), DB::table('orders')->count(), DB::table('payments')->count()];
    $ledger = $untouched();

    ob_start();
    (new Phase5Seeder)->run();
    $printed = (string) ob_get_clean();

    $devices = Device::query()->withoutGlobalScopes()->whereIn('fingerprint', array_keys(Phase5Seeder::DEMO_DEVICES))->orderBy('id')->get()->keyBy('fingerprint');
    $station = fn (string $code): int => (int) Station::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('code', $code)->value('id');
    expect(array_keys(Phase5Seeder::DEMO_DEVICES))->toBe(['demo-pad-1', 'demo-kds-1', 'demo-till-1', 'demo-bridge-1'])
        ->and($devices)->toHaveCount(4)
        ->and(Device::query()->withoutGlobalScopes()->count())->toBe($before + 4)
        ->and($devices->pluck('branch_id')->unique()->all())->toBe([$branch->id])
        ->and($devices->map(fn (Device $d): string => $d->kind->value)->all())
        ->toBe(['demo-pad-1' => 'tablet', 'demo-kds-1' => 'kds', 'demo-till-1' => 'till', 'demo-bridge-1' => 'bridge'])
        ->and($devices['demo-pad-1']->station_id)->toBeNull()
        ->and($devices['demo-kds-1']->station_id)->toEqual($station('kitchen'))
        ->and($devices['demo-till-1']->station_id)->toEqual($station('till'))
        ->and($devices['demo-bridge-1']->token_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($devices->filter(fn (Device $d): bool => $d->token_hash !== null)->keys()->all())->toBe(['demo-bridge-1'])
        ->and($devices->whereNotNull('revoked_at'))->toBeEmpty()
        ->and(DB::table('audit_log')->where('action', 'device.enrol')->whereIn('subject_id', $devices->pluck('id'))->count())->toBe(4)
        ->and($printed)->toBe('');

    $settings = ReceiptSettings::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->sole();
    expect(Phase5Seeder::SAMPLE_TIN)->toMatch('/^0{6}[0-9]{4}$/')
        ->and($settings->tin)->toBe(Phase5Seeder::SAMPLE_TIN)
        ->and($settings->paper_width_mm)->toBe(80)
        ->and(DB::table('audit_log')->where('action', 'receipt_settings.update')->count())->toBe(1);

    // a second run finds all of it and writes nothing; the stock, the orders and the payments are never touched
    app(CurrentBranch::class)->clear();
    expect(phase5Writes(fn () => (new Phase5Seeder)->run()))->toBe([])
        ->and(Device::query()->withoutGlobalScopes()->count())->toBe($before + 4)
        ->and($untouched())->toBe($ledger);
});

it('fills in the TIN and the paper of receipt settings that have no TIN, and keeps a TIN already typed', function () {
    app(Phase0Seeder::class)->run();
    $branch = Phase0Seeder::existingBranch();
    $admin = DB::table('users')->where('phone', DemoBakery::ADMIN_PHONE)->value('id');
    $form = [
        'business_name_en' => 'Demo Bakery', 'business_name_am' => 'ዴሞ ዳቦ ቤት', 'address_en' => null, 'address_am' => null, 'phone' => null,
        'tin' => null, 'footer_en' => null, 'footer_am' => null, 'show_amharic' => true, 'ethiopian_dates' => false, 'paper_width_mm' => 58,
    ];
    actInBranch($branch);
    withActor((int) $admin);
    app(SaveReceiptSettings::class)->handle($form);
    app(CurrentBranch::class)->clear();

    (new Phase5Seeder)->run();

    $settings = ReceiptSettings::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->sole();
    expect($settings->tin)->toBe(Phase5Seeder::SAMPLE_TIN)
        ->and($settings->paper_width_mm)->toBe(80)
        ->and($settings->business_name_am)->toBe('ዴሞ ዳቦ ቤት');

    // a TIN the admin typed is the shop's own: the seed leaves it and the paper alone
    actInBranch($branch);
    withActor((int) $admin);
    app(SaveReceiptSettings::class)->handle(['tin' => '1234567890', 'paper_width_mm' => 58] + $form);
    app(CurrentBranch::class)->clear();
    expect(phase5Writes(fn () => (new Phase5Seeder)->run()))->toBe([]);
    $settings->refresh();
    expect($settings->tin)->toBe('1234567890')
        ->and($settings->paper_width_mm)->toBe(58);
});

it('writes nothing in production, where the live host is never seeded', function () {
    app(Phase0Seeder::class)->run();
    app(CurrentBranch::class)->clear();
    app()->detectEnvironment(fn (): string => 'production');
    putenv('LOAD_DEVICES=3');

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
        ->and(Device::query()->withoutGlobalScopes()->count())->toBe($before + 36 + count(Phase5Seeder::DEMO_DEVICES))
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
