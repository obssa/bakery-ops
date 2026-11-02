<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Backup\StaleCheck;
use Illuminate\Console\Command;

/**
 * `php artisan backup:check-stale` (phase-5 plan section 5, step 5.5c; ADR 0020): raises BackupStale while the newest good backup is older
 * than backup.stale_hours (StaleCheck). Scheduled hourly at a quarter past in routes/console.php on the existing schedule:run cron line;
 * Notifications tells every admin once per stale period. Prints the age only (never a path); exit code 0 either way: a stale backup is an
 * alert, not a failure of the check.
 */
final class BackupCheckStaleCommand extends Command
{
    /** @var string */
    protected $signature = 'backup:check-stale';

    /** @var string */
    protected $description = 'Raise BackupStale when the newest good backup is older than backup.stale_hours';

    public function handle(StaleCheck $check): int
    {
        $stale = $check->check();
        if ($stale === null) {
            $this->line('The newest good backup is recent enough, or no backup has been tried yet.');

            return self::SUCCESS;
        }
        event($stale);
        $this->warn($stale->ageH === null
            ? 'Backup stale: no backup has ended ok since the first try.'
            : sprintf('Backup stale: the newest good backup (run %d) is %d hours old.', $stale->runId, $stale->ageH));

        return self::SUCCESS;
    }
}
