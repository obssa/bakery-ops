<?php

declare(strict_types=1);

namespace App\Shared\Observability;

use Illuminate\Contracts\Foundation\Application;
use Sentry\Laravel\Integration;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;
use Sentry\State\HubInterface;
use Throwable;

/**
 * Error tracking with Sentry (step 5.5a, ADR 0021), off until a DSN is set. The account and its DSN are Ramin's (plan 10.2 Q1): the
 * server .env gets SENTRY_LARAVEL_DSN, then `php artisan config:cache` (docs/runbook/server.md, "Errors").
 *
 * The package is kept out of Laravel's package discovery (composer.json), so without a DSN no Sentry provider is registered: no client, no
 * hub, no listener, no middleware, and no request ever leaves the host. With one, SharedServiceProvider registers the error provider only
 * (never the tracing one: traces stay off), config/sentry.php hands every event to SentryScrubber, and bootstrap/app.php sends what the
 * exception handler reports, which never includes a domain refusal (RefusalLog).
 */
final class ErrorTracking
{
    /** Whether a DSN is configured (config/sentry.php reads SENTRY_LARAVEL_DSN; empty counts as unset). */
    public static function enabled(): bool
    {
        $dsn = config('sentry.dsn');

        return is_string($dsn) && trim($dsn) !== '';
    }

    /** Registers the Sentry provider when a DSN is set (true), and does nothing at all when not (false). */
    public static function register(Application $app): bool
    {
        if (! self::enabled()) {
            return false;
        }
        $app->register(SentryServiceProvider::class);

        return true;
    }

    /** Sends an exception the handler reported to Sentry; without the provider (no DSN) it returns before touching the SDK. */
    public static function capture(Throwable $e): void
    {
        if (! app()->bound(HubInterface::class)) {
            return;
        }
        Integration::captureUnhandledException($e);
    }
}
