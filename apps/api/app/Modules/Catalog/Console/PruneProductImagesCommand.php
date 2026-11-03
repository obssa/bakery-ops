<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Models\ItemImage;
use App\Shared\Tenancy\BranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * `php artisan bakery:prune-product-images {--days=30} {--dry-run}` (step 6.1b, phase-6 plan 4.4 and 10.2 Q19, ADR 0022): a replaced or
 * removed product photo keeps its item_images row (soft deletes are the history) and, until this prune, its three files. A photo here is
 * one sha256 on one disk. Its files are deleted when every row of every branch that names it was soft-deleted more than --days ago: a live
 * row keeps them (two products, even of two branches, may share a photo and its files), and so does a row removed inside the window, which
 * may still be on a tablet's cached menu. Rows are never deleted or changed, so a photo already pruned is counted as such on the next run.
 *
 * Daily at 03:50 Africa/Addis_Ababa (routes/console.php), after the nightly backup of 02:30 has hard-linked the files into its media
 * folder, so a pruned photo stays in the backups of the nights before. Reads every branch: the branch scope is lifted on purpose (no
 * signed-in branch here). Prints counts and bytes, never a path, a key prefix or a sha256; --dry-run deletes nothing and says what it
 * would. A file that cannot be deleted is counted and reported, the run goes on with the rest and exits 1.
 *
 * The window it cannot close: an upload of exactly the same bytes as a photo past the window, in the milliseconds between its
 * "files already there" check and its commit, while this run deletes them. At 03:50, with the shop closed, for a photo nobody has shown
 * for 30 days; the media route would answer 404 (the product shows its placeholder) until the photo is uploaded again, which rewrites
 * the missing files.
 */
final class PruneProductImagesCommand extends Command
{
    private const array RENDITIONS = ['thumb', 'card', 'large'];

    /** @var string */
    protected $signature = 'bakery:prune-product-images
        {--days=30 : Delete the files of photos whose rows were all removed more than this many days ago}
        {--dry-run : Count what would be deleted, delete nothing}';

    /** @var string */
    protected $description = 'Delete the files of product photos removed or replaced more than --days ago that no product shows any more';

    public function handle(): int
    {
        $days = $this->option('days');
        $days = is_int($days) ? (string) $days : $days; // Artisan::call and the scheduler may pass an int
        if (! is_string($days) || preg_match('/^[1-9]\d{0,4}$/', $days) !== 1) {
            $this->error('--days must be a whole number of at least 1.');

            return self::INVALID;
        }
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = CarbonImmutable::now()->subDays((int) $days);

        // every photo (one sha256 on one disk) with a row removed before the cutoff, in every branch
        $old = ItemImage::query()->withoutGlobalScope(BranchScope::class)->onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->toBase()->distinct()->orderBy('sha256')->orderBy('disk')
            ->get(['sha256', 'disk', 'key_prefix', 'mime']);

        // of those, the ones a row still uses: live, or removed inside the window
        $inUse = [];
        foreach ($old->pluck('sha256')->unique()->chunk(500) as $shas) {
            $used = ItemImage::query()->withoutGlobalScope(BranchScope::class)->withTrashed()
                ->whereIn('sha256', $shas->values()->all())
                ->where(fn (Builder $q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>=', $cutoff))
                ->toBase()->distinct()->pluck('sha256');
            foreach ($used as $sha) {
                $inUse[(string) $sha] = true;
            }
        }

        $counts = ['kept' => 0, 'pruned' => 0, 'gone' => 0, 'files' => 0, 'bytes' => 0, 'failed' => 0];
        foreach ($old as $photo) {
            if (isset($inUse[(string) $photo->sha256])) {
                $counts['kept']++;

                continue;
            }
            $this->prune((string) $photo->disk, (string) $photo->key_prefix, ItemImage::extensionOf((string) $photo->mime), $dryRun, $counts);
        }

        $this->line(sprintf(
            'bakery:prune-product-images%s: %d removed photo%s older than %d day%s; %d %s (%d file%s, %d bytes), %d kept (still in use), %d already pruned%s.',
            $dryRun ? ' (dry run, nothing deleted)' : '',
            $old->count(), $old->count() === 1 ? '' : 's', (int) $days, (int) $days === 1 ? '' : 's',
            $counts['pruned'], $dryRun ? 'would be pruned' : 'pruned', $counts['files'], $counts['files'] === 1 ? '' : 's', $counts['bytes'],
            $counts['kept'], $counts['gone'],
            $counts['failed'] > 0 ? ", {$counts['failed']} file(s) could not be deleted" : '',
        ));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Deletes the renditions of one photo that are still on its disk, then its folder once empty; counts what it deleted (or would).
     *
     * @param  array{kept: int, pruned: int, gone: int, files: int, bytes: int, failed: int}  $counts
     */
    private function prune(string $diskName, string $prefix, string $extension, bool $dryRun, array &$counts): void
    {
        try {
            $disk = Storage::disk($diskName);
        } catch (Throwable $e) {
            $counts['failed']++;
            $this->error('A photo\'s disk could not be opened: '.$e::class);

            return;
        }

        $files = 0;
        foreach (self::RENDITIONS as $rendition) {
            $path = $prefix.'/'.$rendition.'.'.$extension;
            try {
                if (! $disk->exists($path)) {
                    continue;
                }
                $bytes = $disk->size($path);
                if (! $dryRun) {
                    $disk->delete($path);
                }
                $files++;
                $counts['files']++;
                $counts['bytes'] += $bytes;
            } catch (Throwable $e) {
                $counts['failed']++;
                $this->error("A {$rendition} rendition could not be deleted: ".$e::class);
            }
        }
        $files > 0 ? $counts['pruned']++ : $counts['gone']++;

        if ($dryRun) {
            return;
        }
        try {
            if ($disk->directoryExists($prefix) && $disk->allFiles($prefix) === []) {
                $disk->deleteDirectory($prefix);
            }
        } catch (Throwable) {
            // an empty folder left behind is harmless: the files are what took the space
        }
    }
}
