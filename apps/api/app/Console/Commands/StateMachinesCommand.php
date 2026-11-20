<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\StateMachines;
use Illuminate\Console\Command;

/**
 * bakery:state-machines (ADR 0032): writes apps/web/src/lib/api/stateMachines.ts from the API's status enums (App\Console\StateMachines).
 * Run it after changing a status enum's cases, transitions() or one of its is*()/has*() answers, and commit the file with the change;
 * tests/Unit/StateMachinesMirrorTest.php fails while the committed file differs. --check reports a stale file and exits 1, writing nothing.
 */
final class StateMachinesCommand extends Command
{
    protected $signature = 'bakery:state-machines {--check : report a stale file and exit 1, write nothing}';

    protected $description = "Write the web's mirror of the API's status machines (apps/web/src/lib/api/stateMachines.ts)";

    public function handle(): int
    {
        $path = base_path(StateMachines::TARGET);
        $wanted = StateMachines::typescript();
        $current = is_file($path) ? (string) file_get_contents($path) : null;
        if ($this->option('check')) {
            if ($current !== $wanted) {
                $this->error('apps/web/src/lib/api/stateMachines.ts is stale: run php artisan bakery:state-machines');

                return self::FAILURE;
            }
            $this->info('stateMachines.ts is current');

            return self::SUCCESS;
        }
        file_put_contents($path, $wanted);
        $this->info('wrote apps/web/src/lib/api/stateMachines.ts');

        return self::SUCCESS;
    }
}
