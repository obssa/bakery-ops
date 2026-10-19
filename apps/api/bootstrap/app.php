<?php

use App\Modules\Identity\Http\Middleware\ResolveActor;
use App\Shared\Http\ApiError;
use App\Shared\Http\Middleware\Idempotency;
use App\Shared\StateMachine\IllegalTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            // Identity (step 0.4): the actor middleware fills CurrentBranch/ActorContext; spatie's aliases enforce rule 6.
            'actor' => ResolveActor::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
        // The branch must be resolved before route model binding and before permission/idempotency middleware run.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveActor::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every API error carries a message_key with its en/am messages (rule 9), including the framework's own.
        $api = fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();
        $exceptions->render(fn (ValidationException $e, Request $request) => $api($request) ? ApiError::json('validation.failed', 422, ['errors' => $e->errors()]) : null);
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $api($request) ? ApiError::json('auth.unauthenticated', 401) : null);
        $exceptions->render(fn (AuthorizationException $e, Request $request) => $api($request) ? ApiError::json('auth.forbidden', 403) : null);
        $exceptions->render(fn (UnauthorizedException $e, Request $request) => $api($request) ? ApiError::json('auth.forbidden', 403) : null);
        $exceptions->render(fn (ModelNotFoundException $e, Request $request) => $api($request) ? ApiError::json('http.not_found', 404) : null);
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $api($request) ? ApiError::json('http.not_found', 404) : null);
        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $api($request) ? ApiError::json('http.too_many_requests', 429)->withHeaders($e->getHeaders()) : null);

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
