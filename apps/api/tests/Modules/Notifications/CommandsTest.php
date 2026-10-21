<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\NotificationDrivers;

pest()->group('module:notifications', 'feature');

/*
 * Step 2.5b (plan section 5 and 10.1): the two commands Ramin runs once on the server. notifications:vapid-keys prints a new
 * key pair and writes nothing (not .env, not config, not a file); notifications:telegram-webhook calls the Bot API's
 * setWebhook with the URL and the secret from the configuration (faked here), and never prints the token or the secret.
 */

beforeEach(function () {
    NotificationDrivers::allOff();
    Http::preventStrayRequests();
});

it('prints a new VAPID key pair as the two environment lines to add, and nothing else of value', function () {
    $exit = Artisan::call('notifications:vapid-keys');
    $output = Artisan::output();

    preg_match('/^VAPID_PUBLIC_KEY=([A-Za-z0-9_-]+)$/m', $output, $public);
    preg_match('/^VAPID_PRIVATE_KEY=([A-Za-z0-9_-]+)$/m', $output, $private);
    expect($exit)->toBe(0)
        ->and($public[1] ?? '')->toHaveLength(87)
        ->and($private[1] ?? '')->toHaveLength(43)
        ->and($output)->toContain('once');
});

it('prints a different pair every time', function () {
    Artisan::call('notifications:vapid-keys');
    $a = Artisan::output();
    Artisan::call('notifications:vapid-keys');
    $b = Artisan::output();

    expect($a)->not->toBe($b);
});

it('writes no file and changes no configuration', function () {
    $before = [];
    foreach ([base_path('.env'), base_path('.env.example'), config_path('notifications.php')] as $file) {
        $before[$file] = is_file($file) ? hash_file('sha256', $file) : null;
    }
    $config = config('notifications.drivers');
    $tmp = glob(storage_path('app/*')) ?: [];

    Artisan::call('notifications:vapid-keys');

    foreach ($before as $file => $hash) {
        expect(is_file($file) ? hash_file('sha256', $file) : null)->toBe($hash);
    }
    expect(config('notifications.drivers'))->toBe($config)
        ->and(glob(storage_path('app/*')) ?: [])->toBe($tmp);
});

it('registers the webhook with the URL carrying the secret, the secret as the header token, and only message updates', function () {
    $telegram = NotificationDrivers::telegram();
    config(['app.url' => 'https://bakery.example.test']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true, 'description' => 'Webhook was set'])]);

    $exit = Artisan::call('notifications:telegram-webhook');
    $output = Artisan::output();

    expect($exit)->toBe(0)->and($output)->toContain('Webhook was set');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === "https://api.telegram.org/bot{$telegram['token']}/setWebhook"
        && $request['url'] === "https://bakery.example.test/api/v1/telegram/webhook/{$telegram['secret']}"
        && $request['secret_token'] === $telegram['secret']
        && $request['allowed_updates'] === ['message']);
    expect($output)->not->toContain($telegram['token'])->not->toContain($telegram['secret']);
});

it('fails with the reason Telegram gives, never printing the token or the secret', function () {
    $telegram = NotificationDrivers::telegram();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);

    $exit = Artisan::call('notifications:telegram-webhook');
    $output = Artisan::output();

    expect($exit)->toBe(1)->and($output)->toContain('Unauthorized')->not->toContain($telegram['token'])->not->toContain($telegram['secret']);
});

it('does nothing, and says which settings are missing, when telegram is not configured', function () {
    NotificationDrivers::telegram();
    config(['notifications.drivers.telegram.webhook_secret' => null]);
    Http::fake();

    $exit = Artisan::call('notifications:telegram-webhook');

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('TELEGRAM_WEBHOOK_SECRET');
    Http::assertNothingSent();
});

it('does not call the Bot API when only the CI array driver is on', function () {
    NotificationDrivers::array();
    Http::fake();

    $exit = Artisan::call('notifications:telegram-webhook');

    expect($exit)->toBe(1);
    Http::assertNothingSent();
});
