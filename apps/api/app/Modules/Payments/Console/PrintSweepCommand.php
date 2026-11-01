<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Support\Printing\PrintJobs;
use Illuminate\Console\Command;

/**
 * `php artisan print:sweep` (step 5.3a, phase-5 plan 4.3; ADR 0019), every minute from the scheduler: a claim the bridge did not report
 * printed or failed within print.claim_ack_seconds goes back to the queue (after the print.max_claims-th claim the job fails,
 * failure_key unacknowledged, and PrintJobFailed is raised), and a job older than print.expire_minutes that nobody is printing is
 * expired. Every branch; each change audited as the person who asked for the print. Safe to run any time: a second run changes nothing.
 */
final class PrintSweepCommand extends Command
{
    /** @var string */
    protected $signature = 'print:sweep';

    /** @var string */
    protected $description = 'Put back unacknowledged print claims and expire receipts that waited too long';

    public function handle(PrintJobs $jobs): int
    {
        $counts = $jobs->sweep();
        $this->line(sprintf('print:sweep requeued %d, failed %d, expired %d', $counts['requeued'], $counts['failed'], $counts['expired']));

        return self::SUCCESS;
    }
}
