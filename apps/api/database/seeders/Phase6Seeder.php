<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Console\Support\DemoProductImages;
use App\Console\Support\DemoProductImagesRefused;
use Database\Seeders\Demo\DemoSeedRefused;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Phase 6 of the demo bakery (phase-6 plan 8, step 6.1c), composed by DemoSeeder after Phase5Seeder when SEED_PHASE is 6 or unset. In the
 * demo branch only, it attaches the demo photo of every product that resources/demo/product-images/manifest.json matches by name_en and
 * that has no photo, with origin `demo`, through bakery:demo-product-images' service (App\Console\Support\DemoProductImages), so through
 * Catalog's ProductPhotos like an upload: the centre crop, the three renditions, catalog_version and the audit row, as the branch's first
 * active admin. A photo an admin uploaded is kept. A second run finds a photo on every product and writes nothing.
 *
 * It writes no stock movement, order, payment or shift, so no earlier gate's figures move: e2e-p0 to e2e-p5 (SEED_PHASE 0 to 5) keep the
 * placeholder path, e2e-p6 runs the photo path. Unlike Phase5Seeder it runs in production too, where bakery:seed-demo --force is the only
 * way it runs (a deploy never seeds): there it does exactly what the coordinator's one run of the command does for a demo branch.
 *
 * @throws DemoSeedRefused when there is no demo branch (Phase 0 first)
 * @throws DemoProductImagesRefused before anything is written: a manifest or photo not fit to attach, or no active admin
 * @throws RuntimeException after the others were attached, when a photo was refused by the pipeline (a seed never skips one)
 */
final class Phase6Seeder extends Seeder
{
    public function run(): void
    {
        $branch = Phase0Seeder::existingBranch() ?? throw DemoSeedRefused::phaseZeroMissing();
        $photos = app(DemoProductImages::class);

        $steps = $photos->apply($branch, $photos->actor($branch));

        $failed = array_filter($steps, fn (array $step): bool => $step['error'] !== null);
        if ($failed !== []) {
            throw new RuntimeException('The demo photo of '.implode(', ', array_map(
                fn (array $step): string => "{$step['name_en']} ({$step['error']})",
                $failed,
            )).' could not be attached; the others were.');
        }
    }
}
