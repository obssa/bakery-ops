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
