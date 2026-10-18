<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Composes the demo bakery phase by phase: Phase0Seeder, then Phase1Seeder, ... up to SEED_PHASE
 * (default: every PhaseNSeeder that exists). Earlier phases' gate e2e projects always run in CI, so a
 * later seeder must extend the world, never rewrite what an earlier gate relies on.
 *
 *   SEED_PHASE=1 php artisan migrate:fresh --seed --seeder=DemoSeeder
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $max = (int) (getenv('SEED_PHASE') !== false ? getenv('SEED_PHASE') : 5);

        for ($n = 0; $n <= $max; $n++) {
            $class = __NAMESPACE__."\Phase{$n}Seeder";
            if (class_exists($class)) {
                $this->call($class);
            }
        }
    }
}
