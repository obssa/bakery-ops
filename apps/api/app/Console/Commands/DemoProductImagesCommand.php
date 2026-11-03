<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\DemoProductImages;
use App\Console\Support\DemoProductImagesRefused;
use App\Shared\Models\Branch;
use Illuminate\Console\Command;

/**
 * `php artisan bakery:demo-product-images --branch=<id> {--as=} {--dry-run} {--force}` (step 6.1c, phase-6 plan 4.4 and 8, ADR 0022):
 * attaches a demo photo (origin `demo`) to each product of a demo branch that has none, matched by name_en from
 * resources/demo/product-images/manifest.json, through Catalog's ProductPhotos like an upload (Support\DemoProductImages). It lives
 * beside bakery:seed-demo, outside the modules, because it is demo tooling; every write still goes through Catalog's code.
 *
 * - Only a demo branch (settings.demo, as bakery:seed-demo makes it): stock photos never land on a shop's own products.
 * - The audit rows name the branch's first active admin, or --as=<user id> (an active user of the branch with catalog.manage); with
 *   neither it refuses before writing.
 * - A photo an admin uploaded is never touched; --force replaces only photos with origin `demo` (after the demo photos changed).
 * - --dry-run prints per product what a run would do and writes nothing, not even a file.
 * - A manifest entry without a source or a licence, a missing file, or a photo that is not 4:3 or under 800 x 600 refuses the run whole.
 *
 * It prints one line per product and the matched, attached, skipped and unmatched counts, the names no entry matched and the entries no
 * product matched; never a path. Running it again only fills in what is missing. On the live host the coordinator runs it once for the
 * Demo Bakery branch, --dry-run first (docs/runbook/server.md, "Demo bakery"); a deploy never runs it.
 */
final class DemoProductImagesCommand extends Command
{
    /** @var string */
    protected $signature = 'bakery:demo-product-images
        {--branch= : the id of the demo branch (bakery:seed-demo prints it)}
        {--as= : the id of the user the audit rows name (default: the branch\'s first active admin)}
        {--dry-run : say per product what would happen; write nothing}
        {--force : replace the demo photos this command attached before; an uploaded photo is never touched}';

    /** @var string */
    protected $description = 'Attach the demo product photos to the products of a demo branch that have none (never an uploaded photo)';

    private const array SAYS = [
        DemoProductImages::ATTACH => ['would attach', 'attached'],
        DemoProductImages::REPLACE => ['would replace (demo photo, --force)', 'replaced (demo photo, --force)'],
        DemoProductImages::KEEP_DEMO => ['skipped: has a photo (a demo one; --force replaces it)', 'skipped: has a photo (a demo one; --force replaces it)'],
        DemoProductImages::KEEP_UPLOAD => ['skipped: has an uploaded photo, never touched', 'skipped: has an uploaded photo, never touched'],
        DemoProductImages::UNMATCHED => ['skipped: no manifest entry', 'skipped: no manifest entry'],
    ];

    public function handle(DemoProductImages $photos): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $branch = $this->branch();
            $as = $this->option('as');
            if ($as !== null && (! is_string($as) || preg_match('/^[1-9]\d{0,18}$/', $as) !== 1)) {
                throw DemoProductImagesRefused::actor(is_string($as) ? "\"{$as}\"" : '', $branch);
            }
            $manifest = $photos->manifest();
            $actor = $photos->actor($branch, is_string($as) ? (int) $as : null);
            $steps = $dryRun ? $photos->plan($branch, $force, $manifest) : $photos->apply($branch, $actor, $force);
        } catch (DemoProductImagesRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('Dry run (--dry-run): nothing is written.');
        }
        $this->info("Demo photos for \"{$branch->name}\" (branch #{$branch->id}), as {$actor->name} (user #{$actor->id}), from ".count($manifest).' manifest entries:');
        $this->table(['product', 'photo', 'what'], array_map(fn (array $step): array => [
            $step['name_en'],
            $step['slug'] ?? '-',
            $step['error'] !== null ? "failed: {$step['error']}" : self::SAYS[$step['action']][$dryRun ? 0 : 1],
        ], $steps));

        $counts = DemoProductImages::counts($steps);
        $this->line($dryRun
            ? "matched: {$counts['matched']}, would attach: {$counts['attach']}, would replace: {$counts['replace']}, skipped: {$counts['skipped']}, unmatched: {$counts['unmatched']}"
            : "matched: {$counts['matched']}, attached: {$counts['attach']}, replaced: {$counts['replace']}, skipped: {$counts['skipped']}, unmatched: {$counts['unmatched']}, failed: {$counts['failed']}");
        $unmatched = array_column(array_filter($steps, fn (array $s): bool => $s['action'] === DemoProductImages::UNMATCHED), 'name_en');
        if ($unmatched !== []) {
            $this->line('No manifest entry matches: '.implode(', ', $unmatched).' (they keep the placeholder; an admin can upload a photo).');
        }
        $unused = DemoProductImages::unused($manifest, $steps);
        if ($unused !== []) {
            $this->line('Entries no product of this branch matched: '.implode(', ', $unused).'.');
        }
        if ($counts['failed'] > 0) {
            $this->error("{$counts['failed']} photo(s) could not be attached; the others were. Fix the cause and run it again: it only fills in what is missing.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @throws DemoProductImagesRefused */
    private function branch(): Branch
    {
        $id = $this->option('branch');
        if (! is_string($id) || preg_match('/^[1-9]\d{0,18}$/', $id) !== 1) {
            throw DemoProductImagesRefused::branch('needs the id of the demo branch (a whole number; bakery:seed-demo prints it)');
        }
        $branch = Branch::query()->find((int) $id);
        if (! $branch instanceof Branch) {
            throw DemoProductImagesRefused::branch("{$id}: no branch has that id");
        }
        if (($branch->settings['demo'] ?? false) !== true) {
            throw DemoProductImagesRefused::notADemoBranch($branch);
        }

        return $branch;
    }
}
