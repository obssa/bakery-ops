<?php

declare(strict_types=1);

namespace App\Modules\Production\Console;

use App\Modules\Production\Support\OverdueCheck;
use Illuminate\Console\Command;

/**
 * `production:overdue` (every 15 minutes, routes/console.php): raises ProductionOverdue for each request nobody has started that waits too
 * long or is past its due time (Support\OverdueCheck). Notifications tells the station's planners once per request.
 */
final class OverdueCommand extends Command
{
    /** @var string */
    protected $signature = 'production:overdue';

    /** @var string */
    protected $description = 'Tell the planners about production requests nobody has started that wait too long or are past their due time';

    public function handle(OverdueCheck $check): int
    {
        $events = $check->check();
        foreach ($events as $event) {
            event($event);
        }
        $this->info(sprintf('%d production request(s) waiting too long or late.', count($events)));

        return self::SUCCESS;
    }
}
