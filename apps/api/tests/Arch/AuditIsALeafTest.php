<?php

declare(strict_types=1);

/*
 * Audit is a leaf (dependency-graph.md, cycle 4; ADR 0001): every module writes through it, so it may import nothing of the application but
 * App\Shared, also now that it reads (step 4.5a: the audit-log routes, the export, the names). The forbidden list is read from the
 * directories on disk: every other module, and every top-level namespace of app/ but Shared (the scaffold's Models, Http, Providers, a
 * Console that may come), so a namespace added later is covered without touching this file. The people's names reach Audit through
 * Shared's ActorNames seam, which Identity binds to its ActorDirectory (tests/Modules/Identity/Feature/ActorNamesBindingTest.php).
 */

$auditForbidden = function (): array {
    $app = dirname(__DIR__, 2).'/app';
    $forbidden = [];
    foreach (glob($app.'/Modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (basename($dir) !== 'Audit') {
            $forbidden[] = 'App\Modules\\'.basename($dir);
        }
    }
    foreach (glob($app.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (! in_array(basename($dir), ['Modules', 'Shared'], true)) {
            $forbidden[] = 'App\\'.basename($dir);
        }
    }
    foreach (glob($app.'/*.php') ?: [] as $file) {
        $forbidden[] = 'App\\'.basename($file, '.php');
    }

    return $forbidden;
};

arch('Audit imports nothing of the application but App\Shared: no other module, nor the scaffold\'s models, HTTP layer or providers')
    ->expect('App\Modules\Audit')
    ->not->toUse($auditForbidden())
    ->group('arch', 'module:audit');

it('reads the forbidden list from disk: every module but Audit, and the scaffold namespaces', function () use ($auditForbidden) {
    expect($auditForbidden())->toContain('App\Modules\Identity', 'App\Modules\Payments', 'App\Modules\Analytics', 'App\Http', 'App\Models', 'App\Providers')
        ->not->toContain('App\Modules\Audit', 'App\Shared', 'App\Modules');
})->group('arch', 'module:audit');

it('takes the names of people through Shared\'s ActorNames seam, the only way Audit learns who someone is', function () {
    $sources = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/Modules/Audit', FilesystemIterator::SKIP_DOTS)) as $file) {
        $sources .= (string) file_get_contents((string) $file);
    }

    expect(interface_exists('App\Shared\Contracts\ActorNames'))->toBeTrue()
        ->and($sources)->toContain('use App\Shared\Contracts\ActorNames;')
        ->and($sources)->not->toContain('ActorDirectory')
        ->and($sources)->not->toContain("'users'");
})->group('arch', 'module:audit');
