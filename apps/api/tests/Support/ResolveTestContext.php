<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Actor\ActorContext;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Closure;
use Illuminate\Http\Request;

/**
 * Test-only route middleware that stands in for authentication on routes a test registers itself: it resolves the
 * branch and the user INSIDE the request, as `auth:sanctum` + `actor` do in production, from what the test put in
 * its static properties. A request no longer inherits the scoped context the test set up (tests/TestCase.php).
 */
final class ResolveTestContext
{
    public static ?Branch $branch = null;

    public static int $userId = 7;

    public static bool $resolved = true;

    public static function reset(?Branch $branch, int $userId = 7): void
    {
        self::$branch = $branch;
        self::$userId = $userId;
        self::$resolved = true;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (self::$resolved && self::$branch instanceof Branch) {
            app(CurrentBranch::class)->set(self::$branch);
            app(ActorContext::class)->authenticate(self::$userId);
        }

        return $next($request);
    }
}
