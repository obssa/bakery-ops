<?php

declare(strict_types=1);

use App\Modules\Notifications\Drivers\ArrayDriver;
use App\Modules\Notifications\Drivers\Driver;
use App\Modules\Notifications\Drivers\DriverRegistry;
use App\Modules\Notifications\Drivers\InAppDriver;
use App\Modules\Notifications\Drivers\PushDriver;
use App\Modules\Notifications\Drivers\SmsDriver;
use App\Modules\Notifications\Drivers\TelegramDriver;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\NotificationDrivers;

pest()->group('module:notifications', 'feature');

/*
 * Step 2.5b (phase-2 plan sections 5 and 10.1): which drivers are on. in_app is the inbox itself and cannot be switched off.
 * Push and Telegram ship OFF and count as on only when their flag is true AND every key they need is present; the CI array
 * driver stands in for both and records instead of sending; SMS is an interface slot with no driver. A driver that is off
 * is never built: the push library is not even loaded.
 */

beforeEach(function () {
    NotificationDrivers::allOff();
    $this->registry = fn (): DriverRegistry => app(DriverRegistry::class);
});

it('ships with every external driver off and in_app always on', function () {
    $registry = ($this->registry)();

    expect($registry->isOn('in_app'))->toBeTrue()
        ->and($registry->isOn('push'))->toBeFalse()
        ->and($registry->isOn('telegram'))->toBeFalse()
        ->and($registry->isOn('sms'))->toBeFalse()
        ->and($registry->for('push'))->toBeNull()
        ->and($registry->for('telegram'))->toBeNull()
        ->and($registry->for('sms'))->toBeNull()
        ->and($registry->for('in_app'))->toBeInstanceOf(InAppDriver::class)
        ->and($registry->for('in_app')?->enabled())->toBeTrue()
        ->and($registry->for('in_app')?->name())->toBe('in_app');
});

it('defaults every flag to false and every key to null in the shipped configuration', function () {
    foreach (['NOTIFY_PUSH_ENABLED', 'NOTIFY_TELEGRAM_ENABLED', 'VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_WEBHOOK_SECRET'] as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
    $config = require base_path('config/notifications.php');

    expect($config['drivers']['push']['enabled'])->toBeFalse()
        ->and($config['drivers']['telegram']['enabled'])->toBeFalse()
        ->and($config['drivers']['push']['public_key'])->toBeNull()
        ->and($config['drivers']['push']['private_key'])->toBeNull()
        ->and($config['drivers']['telegram']['bot_token'])->toBeNull()
        ->and($config['drivers']['telegram']['webhook_secret'])->toBeNull();
})->group('module:notifications', 'unit');

it('counts push as on only when the flag is true and every key it needs is present', function () {
    $keys = NotificationDrivers::push();
    expect(($this->registry)()->isOn('push'))->toBeTrue();

    foreach (['public_key', 'private_key', 'subject'] as $missing) {
        config(["notifications.drivers.push.{$missing}" => '']);
        expect(($this->registry)()->isOn('push'))->toBeFalse("push without {$missing}");
        config(["notifications.drivers.push.{$missing}" => $missing === 'subject' ? 'mailto:ops@example.test' : $keys[$missing === 'public_key' ? 'publicKey' : 'privateKey']]);
    }
    config(['notifications.drivers.push.enabled' => false]);
    expect(($this->registry)()->isOn('push'))->toBeFalse('keys present but the flag is off');
});

it('counts telegram as on only when the flag is true and the token, the bot name and the webhook secret are present', function () {
    NotificationDrivers::telegram();
    expect(($this->registry)()->isOn('telegram'))->toBeTrue();

    foreach (['bot_token', 'bot_username', 'webhook_secret'] as $missing) {
        $kept = config("notifications.drivers.telegram.{$missing}");
        config(["notifications.drivers.telegram.{$missing}" => null]);
        expect(($this->registry)()->isOn('telegram'))->toBeFalse("telegram without {$missing}");
        config(["notifications.drivers.telegram.{$missing}" => $kept]);
    }
    config(['notifications.drivers.telegram.enabled' => false]);
    expect(($this->registry)()->isOn('telegram'))->toBeFalse('keys present but the flag is off');
});

it('builds the real drivers when they are on, each behind the Driver interface', function () {
    NotificationDrivers::push();
    NotificationDrivers::telegram();
    $registry = ($this->registry)();

    expect($registry->for('push'))->toBeInstanceOf(PushDriver::class)->toBeInstanceOf(Driver::class)
        ->and($registry->for('push')?->name())->toBe('push')
        ->and($registry->for('push')?->enabled())->toBeTrue()
        ->and($registry->for('telegram'))->toBeInstanceOf(TelegramDriver::class)->toBeInstanceOf(Driver::class)
        ->and($registry->for('telegram')?->name())->toBe('telegram')
        ->and($registry->for('telegram')?->enabled())->toBeTrue();
});

it('does not build, and so never loads, the push driver while it is off', function () {
    NotificationDrivers::telegram();
    $registry = ($this->registry)();

    $registry->for('push');
    $registry->for('telegram');
    $registry->for('in_app');

    expect($registry->loaded())->toBe(['telegram', 'in_app'])
        ->and($registry->loaded())->not->toContain('push');
});

it('stands the array driver in for push and telegram when it is on, even when real keys exist', function () {
    NotificationDrivers::push();
    NotificationDrivers::telegram();
    NotificationDrivers::array();
    $registry = ($this->registry)();

    expect($registry->isOn('push'))->toBeTrue()
        ->and($registry->isOn('telegram'))->toBeTrue()
        ->and($registry->for('push'))->toBeInstanceOf(ArrayDriver::class)
        ->and($registry->for('push')?->name())->toBe('push')
        ->and($registry->for('telegram'))->toBeInstanceOf(ArrayDriver::class)
        ->and($registry->for('telegram')?->name())->toBe('telegram')
        ->and($registry->for('sms'))->toBeNull();
});

it('has a Driver slot for SMS and no class that implements it', function () {
    expect(interface_exists(SmsDriver::class))->toBeTrue()
        ->and(is_subclass_of(SmsDriver::class, Driver::class))->toBeTrue();
    $implementations = array_filter(get_declared_classes(), fn (string $class): bool => is_subclass_of($class, SmsDriver::class));
    expect($implementations)->toBe([]);
});

it('prints which drivers are on and why not, and never a key', function () {
    $push = NotificationDrivers::push();
    config(['notifications.drivers.push.private_key' => '']);
    $telegram = NotificationDrivers::telegram();
    config(['notifications.drivers.telegram.enabled' => false]);

    $exit = Artisan::call('notifications:status');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('in_app')->toContain('push')->toContain('telegram')->toContain('sms')
        ->and($output)->toContain('VAPID_PRIVATE_KEY')
        ->and($output)->toContain('NOTIFY_TELEGRAM_ENABLED')
        ->and($output)->not->toContain($push['publicKey'])
        ->and($output)->not->toContain($telegram['token'])
        ->and($output)->not->toContain($telegram['secret']);
});
