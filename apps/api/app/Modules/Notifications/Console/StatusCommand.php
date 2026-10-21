<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Drivers\DriverRegistry;
use Illuminate\Console\Command;

/**
 * `php artisan notifications:status`: which notification drivers are on, and for those that are not, which settings are
 * missing (their names only, never a value). The first thing to run after editing the server .env (plan section 10.1).
 */
final class StatusCommand extends Command
{
    /** @var string */
    protected $signature = 'notifications:status';

    /** @var string */
    protected $description = 'Show which notification drivers are on and, when not, which settings are missing (never a key)';

    public function handle(DriverRegistry $drivers): int
    {
        $rows = [];
        foreach (DriverRegistry::CHANNELS as $channel) {
            $state = $drivers->state($channel);
            $rows[] = [$channel, $state['on'] ? 'on' : 'off', $state['on'] ? $state['via'] : implode('; ', $state['why'])];
        }
        $this->table(['channel', 'state', 'via / why not'], $rows);

        return self::SUCCESS;
    }
}
