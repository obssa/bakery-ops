<?php

declare(strict_types=1);

namespace App\Shared\Files;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/** A Laravel log `tap` (config/logging.php): puts LogScrubProcessor on a channel's Monolog logger. */
final class ScrubLogs
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new LogScrubProcessor);
        }
    }
}
