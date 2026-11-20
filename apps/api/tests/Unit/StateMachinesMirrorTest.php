<?php

declare(strict_types=1);

use App\Console\StateMachines;

pest()->group('unit');

/*
 * The web's mirror of the status machines (ADR 0032) is the API's own output: apps/web/src/lib/api/stateMachines.ts must be exactly what
 * `php artisan bakery:state-machines` writes from the enums, so a status added, a transition changed or an isRunning() moved shows up in
 * the web in the same PR, or this fails.
 */

it('keeps apps/web/src/lib/api/stateMachines.ts the output of the enums', function () {
    $path = dirname(__DIR__, 2).'/'.StateMachines::TARGET;

    expect(is_file($path))->toBeTrue('apps/web/src/lib/api/stateMachines.ts is missing: run php artisan bakery:state-machines')
        ->and((string) file_get_contents($path))->toBe(StateMachines::typescript(), 'stateMachines.ts is stale: run php artisan bakery:state-machines');
});

it('names every status machine of the API', function () {
    $root = dirname(__DIR__, 2).'/app/Modules';
    $enums = [];
    foreach (glob($root.'/*/Enums/*.php') ?: [] as $file) {
        $class = 'App\\Modules\\'.basename(dirname($file, 2)).'\\Enums\\'.basename($file, '.php');
        if (enum_exists($class) && method_exists($class, 'transitions')) {
            $enums[] = $class;
        }
    }
    sort($enums);
    $listed = array_values(StateMachines::MACHINES);
    sort($listed);

    expect($listed)->toBe($enums);
});
