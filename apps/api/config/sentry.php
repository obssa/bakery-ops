<?php

use App\Shared\Observability\SentryScrubber;

/*
 * Error tracking (phase 5 step 5.5a, ADR 0021). OFF until SENTRY_LARAVEL_DSN is set in the server .env: the Sentry account and its DSN
 * are Ramin's (plan 10.2 Q1) and never live in the repository. Without a DSN, App\Shared\Observability\ErrorTracking registers no Sentry
 * provider (the package is kept out of package discovery in composer.json), so there is no client and no network at all.
 *
 * With a DSN: errors only, nothing personal (plan 10.2 D21). send_default_pii is false and before_send (SentryScrubber) removes PINs,
 * passwords, tokens, the Authorization and Cookie headers, phone numbers, signed-URL parameters and the bodies of the identity routes.
 * Traces, profiles, logs and metrics stay off (the tracing provider is never registered either). Domain refusals never reach Sentry: the
 * exception handler does not report them (bootstrap/app.php). Every value here survives `php artisan config:cache` (DeployableCachesTest).
 */
return [
    'dsn' => env('SENTRY_LARAVEL_DSN') ?: null,

    'release' => env('SENTRY_RELEASE'),

    // empty: the Laravel environment (APP_ENV)
    'environment' => env('SENTRY_ENVIRONMENT'),

    'sample_rate' => 1.0,

    'traces_sample_rate' => null,

    'profiles_sample_rate' => null,

    'enable_logs' => false,

    'enable_metrics' => false,

    'send_default_pii' => false,

    // a request body up to about 1 kB is attached (scrubbed, and never an identity route's); larger ones are left out
    'max_request_body_size' => 'small',

    'before_send' => [SentryScrubber::class, 'beforeSend'],

    'ignore_transactions' => ['/up', '/api/v1/health'],

    // breadcrumbs ride along with an error only; SQL bindings never (they hold phone numbers and names)
    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

    // the tracing provider is never registered; these say the same to anything that reads them
    'tracing' => [
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'sql_origin' => false,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => false,
        'cache' => false,
        'redis_commands' => false,
        'notifications' => false,
        'missing_routes' => false,
        'continue_after_response' => false,
        'default_integrations' => false,
    ],
];
