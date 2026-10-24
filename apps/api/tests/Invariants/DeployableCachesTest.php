<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

/*
 * The deploy runs `php artisan optimize` on the server (scripts/deploy/release.sh). If any cache step fails there,
 * the release is rolled back. These checks fail in CI first: e.g. config/view.php points at resources/views, and
 * git does not keep an empty folder, so removing the last Blade view broke view:cache on 2026-10-20.
 */

it('keeps every configured view path present so view:cache works on the server', function () {
    foreach (config('view.paths') as $path) {
        expect(is_dir($path))->toBeTrue("view path missing: {$path} (keep a .gitkeep in it)");
    }
})->group('invariants');

it('can compile the view cache the way the deploy does', function () {
    try {
        expect(Artisan::call('view:cache'))->toBe(0);
    } finally {
        Artisan::call('view:clear');
    }
})->group('invariants');

/*
 * Step 3.7e: the three Phase 3 shift rules are read from the server .env (the kill switch is false there plus `php artisan config:cache`,
 * docs/runbook/server.md). So the config must cache cleanly and the cache must carry what the environment says, both ways. A closure or a
 * value that does not survive var_export would fail the deploy's `php artisan optimize` and roll the release back. Each run is a real
 * `php artisan config:cache` in a child process whose environment names the variables (a real environment variable beats the .env
 * file, as it does on the server), then the cached file is read.
 */
const SHIFT_RULES = [
    'LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS' => 'ledger.require_shift_for_station_locations',
    'SHIFTS_OPEN_AD_HOC_ON_ACCEPT' => 'shifts.open_ad_hoc_on_accept',
    'PAYMENTS_REQUIRE_SHIFT_FOR_CASH' => 'payments.require_shift_for_cash',
];

it('ships the three shift rules on in the config files, .env.example and .env.ci', function () {
    foreach (SHIFT_RULES as $env => $path) {
        [$file] = explode('.', $path);
        expect((string) file_get_contents(config_path("{$file}.php")))->toContain("env('{$env}', true)");
        foreach (['.env.example', '.env.ci'] as $envFile) {
            expect((string) file_get_contents(base_path($envFile)))->toContain("{$env}=true");
        }
    }
})->group('invariants');

it('caches the config with the three shift rules switched off, and on again', function (string $value, bool $expected) {
    $cache = app()->getCachedConfigPath();
    try {
        $process = new Process([PHP_BINARY, 'artisan', 'config:cache'], base_path(), array_fill_keys(array_keys(SHIFT_RULES), $value) + ['APP_CONFIG_CACHE' => $cache]);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

        $cached = require $cache;
        foreach (SHIFT_RULES as $path) {
            expect(data_get($cached, $path))->toBe($expected, "{$path} with the variable {$value}");
        }
    } finally {
        @unlink($cache);
    }
})->with([['false', false], ['true', true]])->group('invariants');

/*
 * Step 4.2a: the files disk is named by FILES_DISK (ADR 0015). The config must cache with the variable unset (the live host: the local
 * private disk) and set to files_s3 (the move to a bucket), and the cache must carry what the environment says.
 */
it('caches the files config with and without FILES_DISK', function (?string $value, string $expected) {
    $cache = app()->getCachedConfigPath();
    $env = ['APP_CONFIG_CACHE' => $cache, 'FILES_DISK' => $value ?? false];
    try {
        $process = new Process([PHP_BINARY, 'artisan', 'config:cache'], base_path(), $env);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

        $cached = require $cache;
        expect(data_get($cached, 'files.disk'))->toBe($expected)
            ->and(data_get($cached, 'filesystems.disks.files.root'))->toBe(storage_path('app/private/files'))
            ->and(data_get($cached, 'filesystems.disks.files_s3.driver'))->toBe('s3')
            ->and(data_get($cached, 'files.max_edge'))->toBe(1600);
    } finally {
        @unlink($cache);
    }
})->with([[null, 'files'], ['files_s3', 'files_s3']])->group('invariants');
