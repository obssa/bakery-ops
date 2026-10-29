<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Phase 5 of the demo bakery (phase-5 plan section 8): a skeleton since step 5.1a, composed by DemoSeeder after Phase4Seeder when
 * SEED_PHASE is 5 or unset. It writes nothing yet. Step 5.6b makes it enrol the demo branch's pad, KDS, till and bridge (fixed
 * fingerprints demo-pad-1, demo-kds-1, demo-till-1, demo-bridge-1; the bridge's token made at random and never printed), set the receipt
 * settings' sample TIN and paper width, and with LOAD_DEVICES=N enrol load-01 to load-N for perf-load. It will never write a stock
 * movement, an order or a payment, so no earlier gate's figures move; the live host is never seeded by a deploy.
 */
final class Phase5Seeder extends Seeder
{
    public function run(): void
    {
        // nothing yet (step 5.6b)
    }
}
