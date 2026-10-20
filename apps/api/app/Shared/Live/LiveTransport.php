<?php

declare(strict_types=1);

namespace App\Shared\Live;

/**
 * Is a WebSocket transport configured (ADR 0009 decision 5)? The driver of the default broadcast connection decides:
 * `null` sends nothing and `log` only writes the log, so neither delivers anything to a screen, and queueing broadcast
 * jobs for them on the live host's cron-drained queue is exactly what must not happen. reverb, pusher (or any other
 * real driver) are a transport. Switching is .env only: BROADCAST_CONNECTION=reverb|pusher and its keys.
 */
final class LiveTransport
{
    /** drivers that deliver nothing to a client */
    public const array SILENT_DRIVERS = ['null', 'log'];

    public static function isOn(): bool
    {
        $driver = self::driver();

        return $driver !== null && ! in_array($driver, self::SILENT_DRIVERS, true);
    }

    /**
     * The driver of the default broadcast connection; null when that connection is not configured. env() reads
     * BROADCAST_CONNECTION=null as PHP null, which Laravel's BroadcastManager resolves to the null driver: so does this.
     */
    public static function driver(): ?string
    {
        $connection = config('broadcasting.default');
        if ($connection === null || $connection === '' || $connection === 'null') {
            return 'null';
        }
        if (! is_string($connection)) {
            return null;
        }
        $driver = config("broadcasting.connections.{$connection}.driver");

        return is_string($driver) && $driver !== '' ? $driver : null;
    }
}
