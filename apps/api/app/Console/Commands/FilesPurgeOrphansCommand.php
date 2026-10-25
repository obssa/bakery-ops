<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Shared\Files\Files;
use App\Shared\Files\StoredFile;
use App\Shared\Tenancy\BranchScope;
use Illuminate\Console\Command;
use Throwable;

/**
 * `files:purge-orphans`: removes the photos nobody attached within `files.orphan_hours` (24), in every branch. Each one goes through
 * Files::delete, which removes the object and soft-deletes the row. An attached photo and a young one are never touched. Hourly from the
 * existing schedule:run line (routes/console.php). A failure on one file is counted and reported, and the run goes on with the rest.
 */
final class FilesPurgeOrphansCommand extends Command
{
    /** @var string */
    protected $signature = 'files:purge-orphans';

    /** @var string */
    protected $description = 'Remove the uploaded photos that were never attached within files.orphan_hours';

    public function handle(): int
    {
        $limit = now()->subHours(max(1, (int) config('files.orphan_hours')));
        $store = app(Files::class);
        $removed = 0;
        $failed = 0;

        // no signed-in branch here: the purge reads across branches, so the branch scope is lifted on purpose
        StoredFile::query()->withoutGlobalScope(BranchScope::class)
            ->whereNull('attached_at')->whereNull('attached_type')
            ->where('created_at', '<', $limit)
            ->orderBy('id')
            ->chunkById(100, function ($files) use ($store, &$removed, &$failed): void {
                foreach ($files as $file) {
                    try {
                        $store->delete($file);
                        $removed++;
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error("Could not remove file #{$file->id}: ".$e::class);
                    }
                }
            });

        $this->info("files:purge-orphans: removed {$removed}".($failed > 0 ? ", {$failed} failed" : '').'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
