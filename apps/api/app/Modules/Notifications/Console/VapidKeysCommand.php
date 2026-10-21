<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * `php artisan notifications:vapid-keys`: makes a new VAPID key pair for web push and prints it ONCE. It stores nothing: not
 * in .env, not in the configuration, not in a file, not in the log. The person running it copies the two lines into the
 * server's .env (docs/runbook/server.md, "Turning on web push and Telegram"); a pair that is lost is replaced by running this
 * again and subscribing the browsers again. The keys must never enter the repository (tests/Invariants/NoSecretsInEnvExampleTest.php).
 */
final class VapidKeysCommand extends Command
{
    /** @var string */
    protected $signature = 'notifications:vapid-keys';

    /** @var string */
    protected $description = 'Print a new VAPID key pair for web push (shown once, nothing is written anywhere)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('# A new VAPID key pair. It is shown once and not stored: add both lines to the server .env, then run config:cache.');
        $this->line('# The private key is a secret: never put it in the repository, a chat or a ticket.');
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
