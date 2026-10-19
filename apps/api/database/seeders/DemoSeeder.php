<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Composes the demo bakery phase by phase: Phase0Seeder, then Phase1Seeder, ... up to SEED_PHASE (default: every
 * PhaseNSeeder that exists). Earlier phases' gate e2e projects always run in CI, so a later seeder must extend the world,
 * never rewrite what an earlier gate relies on. Each phase seeder is idempotent and writes only into the demo branch
 * (bakery.demo.branch); `php artisan bakery:seed-demo` runs the same list into a named branch, on the live site too.
 *
 *   SEED_PHASE=1 php artisan migrate:fresh --seed --seeder=DemoSeeder
 */
final class DemoSeeder extends Seeder
{
    public const int LAST_PHASE = 5;

    public function run(): void
    {
        foreach (self::seedersUpTo(self::maxPhase()) as $class) {
            $this->call($class);
        }
    }

    /** SEED_PHASE when it is set, else every phase. */
    public static function maxPhase(): int
    {
        $phase = getenv('SEED_PHASE');

        return $phase !== false && $phase !== '' ? (int) $phase : self::LAST_PHASE;
    }

    /**
     * The phase seeders that exist, Phase0Seeder first, up to and including phase $max.
     *
     * @return list<class-string<Seeder>>
     */
    public static function seedersUpTo(int $max): array
    {
        $seeders = [];
        for ($n = 0; $n <= min($max, self::LAST_PHASE); $n++) {
            $class = __NAMESPACE__."\\Phase{$n}Seeder";
            if (! class_exists($class)) {
                continue;
            }
            /** @var class-string<Seeder> $class every PhaseNSeeder extends Seeder */
            $seeders[] = $class;
        }

        return $seeders;
    }
}
