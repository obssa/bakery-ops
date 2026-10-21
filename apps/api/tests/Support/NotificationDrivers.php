<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Notifications\Drivers\SentLog;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;

/**
 * Switches the external drivers of the notification module on and off for a test (step 2.5b). Every driver ships off, so a
 * test starts from allOff() and turns on exactly what it needs. Nothing here is a real key: a VAPID pair is generated at
 * run time, a bot token and a webhook secret are random strings, so no secret-shaped literal sits in the repository
 * (tests/Invariants/NoSecretsInEnvExampleTest.php scans these files too).
 */
final class NotificationDrivers
{
    public static function allOff(): void
    {
        config([
            'notifications.drivers.push' => ['enabled' => false, 'subject' => null, 'public_key' => null, 'private_key' => null],
            'notifications.drivers.telegram' => ['enabled' => false, 'bot_token' => null, 'bot_username' => null, 'webhook_secret' => null, 'api_base' => 'https://api.telegram.org'],
            'notifications.drivers.array' => ['enabled' => false],
        ]);
    }

    /** @return array{publicKey: string, privateKey: string} the generated pair */
    public static function push(): array
    {
        $keys = VAPID::createVapidKeys();
        config([
            'notifications.drivers.push' => [
                'enabled' => true, 'subject' => 'mailto:ops@example.test', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey'],
            ],
        ]);

        return $keys;
    }

    /** @return array{token: string, secret: string, username: string} */
    public static function telegram(string $username = 'example_test_bot'): array
    {
        $token = random_int(100000000, 999999999).':'.Str::random(10);
        $secret = Str::random(40);
        config([
            'notifications.drivers.telegram' => [
                'enabled' => true, 'bot_token' => $token, 'bot_username' => $username, 'webhook_secret' => $secret, 'api_base' => 'https://api.telegram.org',
            ],
        ]);

        return ['token' => $token, 'secret' => $secret, 'username' => $username];
    }

    /** The CI driver: it stands in for push and telegram and records what it was asked to send. */
    public static function array(): SentLog
    {
        config(['notifications.drivers.array' => ['enabled' => true]]);

        return app(SentLog::class);
    }
}
