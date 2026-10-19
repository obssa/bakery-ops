<?php

use App\Shared\Http\ApiError;
use App\Shared\Http\Middleware\Idempotency;
use App\Shared\StateMachine\IllegalTransition;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every write route under /api/v1 carries `idempotency` (rule 7); tests/Invariants/IdempotencyCoverageTest.php checks.
        $middleware->alias([
            'idempotency' => Idempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A status change the machine does not allow is a conflict with the current state, told bilingually.
        $exceptions->render(function (IllegalTransition $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiError::json($e->messageKey(), 409, [
                'machine' => $e->machine,
                'from' => $e->from,
                'to' => $e->to,
            ]);
        });
    })->create();
