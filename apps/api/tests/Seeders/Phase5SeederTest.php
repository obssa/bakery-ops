<?php

declare(strict_types=1);

use Database\Seeders\DemoSeeder;
use Database\Seeders\Phase4Seeder;
use Database\Seeders\Phase5Seeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

pest()->group('feature', 'seeds');

/*
 * Step 5.1a (phase-5 plan 8): the Phase 5 demo seeder is a skeleton until step 5.6b. DemoSeeder composes it after Phase4Seeder when
 * SEED_PHASE is 5 or unset, and it writes nothing yet, so no earlier gate's figures move and `bakery:seed-demo` on the live site adds
 * nothing (the live host is never seeded by a deploy either).
 */

it('composes Phase 5 after Phase 4', function () {
    $seeders = DemoSeeder::seedersUpTo(5);

    expect(end($seeders))->toBe(Phase5Seeder::class)
        ->and($seeders[count($seeders) - 2])->toBe(Phase4Seeder::class)
        ->and(DemoSeeder::seedersUpTo(4))->not->toContain(Phase5Seeder::class);
});

it('writes nothing yet', function () {
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    (new Phase5Seeder)->run();

    expect($writes)->toBe([]);
});
