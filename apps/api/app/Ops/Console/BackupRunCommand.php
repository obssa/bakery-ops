<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Backup\BackupRunner;
use App\Ops\Models\OpsRun;
use Illuminate\Console\Command;

/**
 * `php artisan backup:run` (phase-5 plan 5.5b; ADR 0020; docs/runbook/backup-restore.md): one backup of the app's database and files
 * folder into BACKUP_PATH. Scheduled daily at 02:30 Africa/Addis_Ababa in routes/console.php on the existing schedule:run cron line;
 * by hand from the runbook. Prints the run and its result (never a path); exit code 0 when the backup is good, 1 when it failed.
 */
final class BackupRunCommand extends Command
{
    /** @var string */
    protected $signature = 'backup:run';

    /** @var string */
    protected $description = 'Dump the database and snapshot the files and media folders into the backup folder, then apply retention';

    public function handle(BackupRunner $runner): int
    {
        $run = $runner->run();
        $detail = $run->detail ?? [];
        if ($run->status === OpsRun::OK) {
            $this->line(sprintf('Backup %d ok: %s, %d bytes, sha256 %s, %d kept.', $run->id, $run->path_hint, $run->bytes, $run->sha256, (int) ($detail['kept'] ?? 0)));

            return self::SUCCESS;
        }
        $this->error(sprintf('Backup %d failed: %s', $run->id, (string) ($detail['failure_key'] ?? 'unknown')));

        return self::FAILURE;
    }
}
