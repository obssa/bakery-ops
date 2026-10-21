<?php

declare(strict_types=1);

namespace App\Modules\Production;

use App\Modules\Production\Listeners\CreateAutoRequest;
use App\Shared\Events\StockOut;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Production (layer 5): routes and the one listener that turns a stock-out into a request for the baker (StockOut ->
 * CreateAutoRequest, queued after commit on the default queue, ADR 0011). The contracts (Requests, and Batches from step 2.7c)
 * and the actions are plain classes resolved per use: they carry the scoped ActorContext / CurrentBranch, so a singleton would
 * leak one request into the next under Octane (traps.md). The Identity bindings Production relies on (the presence resolver
 * AssignedUsersResolver and the shift attacher NullShiftAttacher, dependency-graph.md cycle 3) are made by Identity until
 * Phase 3 rebinds them.
 */
final class ProductionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(StockOut::class, [CreateAutoRequest::class, 'handle']);

        $this->loadRoutesFrom(base_path('routes/modules/production.php'));
    }
}
