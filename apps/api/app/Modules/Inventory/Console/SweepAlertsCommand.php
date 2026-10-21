<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Alerts\AlertSweeper;
use Illuminate\Console\Command;

/**
 * `php artisan inventory:sweep-alerts`, scheduled hourly in routes/console.php: queue the stock alert evaluation of every balance with
 * an open episode or a live reorder rule (Alerts\AlertSweeper), so a lost job heals within the hour. Safe to run any time and as often
 * as wanted: the evaluation is unique until it starts and idempotent. `--chunk=` is how many rules or episodes are read at a time.
 */
final class SweepAlertsCommand extends Command
{
    /** @var string */
    protected $signature = 'inventory:sweep-alerts {--chunk=500 : How many rules or episodes to read at a time}';

    /** @var string */
    protected $description = 'Re-queue the stock alert evaluation of every balance with an open episode or a live reorder rule';

    public function handle(AlertSweeper $sweeper): int
    {
        $chunk = $this->option('chunk');
        if (! is_numeric($chunk) || (int) $chunk < 1 || (string) (int) $chunk !== (string) $chunk) {
            $this->error('--chunk must be a whole number of at least 1.');

            return self::INVALID;
        }

        $queued = $sweeper->sweep((int) $chunk);
        $this->info("Queued {$queued} stock alert evaluation(s).");

        return self::SUCCESS;
    }
}
