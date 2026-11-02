<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Drill\DrillRunner;
use App\Ops\Models\OpsRun;
use Illuminate\Console\Command;

/**
 * `php artisan backup:drill [--keep]` (phase-5 plan 5.5b; ADR 0020; docs/runbook/backup-restore.md): restore the newest good dump into
 * BACKUP_DRILL_DATABASE and prove it (ledger oracle, hash chains, triggers, row counts), then empty that database again; `--keep`
 * leaves the restored copy for inspection (the CI job reads it). Scheduled on Sundays at 03:30 Africa/Addis_Ababa in routes/console.php.
 * Exit code 0 when the drill passed or was skipped (no drill database configured), 1 when it failed.
 */
final class BackupDrillCommand extends Command
{
    /** @var string */
    protected $signature = 'backup:drill {--keep : Leave the restored database in place instead of emptying it}';

    /** @var string */
    protected $description = 'Restore the newest good backup into the drill database and check the ledger, the chains and the triggers';

    public function handle(DrillRunner $drill): int
    {
        $run = $drill->run((bool) $this->option('keep'));
        $detail = $run->detail ?? [];
        if ($run->status === OpsRun::SKIPPED) {
            $this->line(sprintf('Drill %d skipped: %s', $run->id, (string) ($detail['reason_key'] ?? '')));

            return self::SUCCESS;
        }
        if ($run->status === OpsRun::OK) {
            $ledger = (array) ($detail['ledger'] ?? []);
            $chains = (array) ($detail['chains'] ?? []);
            $this->line(sprintf(
                'Drill %d ok: %s restored; ledger %d items in %d places, chains %d places and %d links whole, triggers refuse, row counts match.',
                $run->id, $run->path_hint, (int) ($ledger['items'] ?? 0), (int) ($ledger['locations'] ?? 0), (int) ($chains['places'] ?? 0), (int) ($chains['links'] ?? 0),
            ));

            return self::SUCCESS;
        }
        $this->error(sprintf('Drill %d failed: %s', $run->id, implode(', ', array_map('strval', (array) ($detail['failures'] ?? [$detail['failure_key'] ?? 'unknown'])))));

        return self::FAILURE;
    }
}
