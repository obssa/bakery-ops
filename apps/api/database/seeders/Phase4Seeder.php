<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Demo\DemoSeedRefused;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;

/**
 * Phase 4 of the demo bakery (phase-4 plan section 8): payments and analytics. THIS IS THE SKELETON of step 4.1a. It exists because
 * `gate_check --mode entry --phase 4` requires the file, and because DemoSeeder composes every PhaseNSeeder that exists, after
 * Phase3Seeder, up to SEED_PHASE: the demo world it extends is the Phase 0 to 3 world, in the same branch. It writes NOTHING yet.
 * Step 4.6a replaces the body with the 90-day history (splits, voids, refunds, receipt photos, `Rebuild::day` for every day written).
 */
final class Phase4Seeder extends Seeder
{
    private ?Command $console = null;

    public function setCommand(Command $command): static
    {
        $this->console = $command;

        return parent::setCommand($command);
    }

    /**
     * @throws DemoSeedRefused no demo branch yet (Phase 0 comes first)
     */
    public function run(): void
    {
        if (Phase0Seeder::existingBranch() === null) {
            throw DemoSeedRefused::phaseZeroMissing();
        }

        if ($this->console instanceof Command) {
            $this->console->info('Phase 4: nothing to seed yet (the history arrives with step 4.6a).');
        }
    }
}
