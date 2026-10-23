<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

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
 * docs/runbook/server.md). So the config must cache cleanly, and the cache must carry whatever the environment says: on by default, off
 * when the variables say false, and the same again when they say true. A closure or a value that does not survive var_export would fail
 * the deploy's `php artisan optimize` and roll the release back.
 */
it('caches the config with the three shift rules on by default, off when the environment says so, and on again', function () {
    $keys = [
        'LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS' => 'ledger.require_shift_for_station_locations',
        'SHIFTS_OPEN_AD_HOC_ON_ACCEPT' => 'shifts.open_ad_hoc_on_accept',
        'PAYMENTS_REQUIRE_SHIFT_FOR_CASH' => 'payments.require_shift_for_cash',
    ];
    $saved = [];
    foreach (array_keys($keys) as $env) {
        $saved[$env] = [$_ENV[$env] ?? null, $_SERVER[$env] ?? null];
    }
    $cachedValues = function () use ($keys): array {
        expect(Artisan::call('config:cache'))->toBe(0);
        $cached = require app()->getCachedConfigPath();

        return array_map(fn (string $path): mixed => data_get($cached, $path), $keys);
    };

    try {
        foreach (array_keys($keys) as $env) {
            unset($_ENV[$env], $_SERVER[$env]);
        }
        $defaults = $cachedValues();
        expect(array_values($defaults))->toBe([true, true, true]);

        foreach (array_keys($keys) as $env) {
            $_ENV[$env] = $_SERVER[$env] = 'false';
        }
        expect(array_values($cachedValues()))->toBe([false, false, false]);

        foreach (array_keys($keys) as $env) {
            $_ENV[$env] = $_SERVER[$env] = 'true';
        }
        expect(array_values($cachedValues()))->toBe([true, true, true]);
    } finally {
        foreach ($saved as $env => [$fromEnv, $fromServer]) {
            unset($_ENV[$env], $_SERVER[$env]);
            if ($fromEnv !== null) {
                $_ENV[$env] = $fromEnv;
            }
            if ($fromServer !== null) {
                $_SERVER[$env] = $fromServer;
            }
        }
        Artisan::call('config:clear');
    }
})->group('invariants');
