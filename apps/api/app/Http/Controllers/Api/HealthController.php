<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Public liveness/readiness probe used by compose health checks, CI e2e and the Phase 0 smoke test.
 * Never reports secrets; only whether the ledger's database answers.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'down';
        }

        return response()->json([
            'status' => $database === 'ok' ? 'ok' : 'degraded',
            'database' => $database,
            'app' => config('app.name'),
            'time' => now()->toIso8601String(),
        ], $database === 'ok' ? 200 : 503);
    }
}
