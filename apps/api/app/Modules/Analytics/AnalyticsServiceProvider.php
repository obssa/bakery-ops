<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Modules\Analytics\Console\RebuildCommand;
use App\Modules\Analytics\Console\WeeklySummaryCommand;
use App\Modules\Analytics\Listeners\RebuildAfterShiftLocked;
use App\Modules\Analytics\Listeners\TouchToday;
use App\Modules\Analytics\Support\SignedWeeklySummaryLinks;
use App\Shared\Events\OrderPaid;
use App\Shared\Events\OrderVoided;
use App\Shared\Events\PaymentDisputed;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Events\PaymentVerified;
use App\Shared\Events\PaymentVoided;
use App\Shared\Events\ShiftLocked;
use App\Shared\Reports\WeeklySummaryLinks;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Analytics (layer 7, phase 4; phase-4 plan section 6 item 4). Read-only over the rest of the system: it reads the other modules only
 * through their Facts ports, Catalog's contracts and Shared (ModuleBoundariesTest), writes only its own summary tables, and audits through
 * Audit's AuditedAction (the on-demand rebuild). Step 4.4a brought the tables, Rebuild::day, the RebuildDay job and `analytics:rebuild`;
 * step 4.4b the listeners (a locked shift rebuilds its days; a payment or an order paid or voided touches today, debounced), the routes of
 * the freshness feed and the on-demand rebuild, and the schedule (routes/console.php). The dashboards are 4.4c; step 4.4d the exports, the
 * weekly summary (`analytics:weekly-summary`, its send route and its public PDF) and the binding of Shared's WeeklySummaryLinks. Every
 * class is resolved per use: they carry the scoped CurrentBranch, so a singleton would leak a branch from one job into the next (traps.md,
 * Octane).
 */
final class AnalyticsServiceProvider extends ServiceProvider
{
    /** The events that move today's figures (phase-4 plan section 5). Refunds join with steps 4.1b and 4.3d. */
    public const array TOUCHES_TODAY = [
        PaymentRecorded::class,
        PaymentVerified::class,
        PaymentDisputed::class,
        PaymentVoided::class,
        OrderPaid::class,
        OrderVoided::class,
    ];

    public function register(): void
    {
        // the signed link to a weekly summary's PDF, which Notifications puts in the weekly message (Shared binds a null one first)
        $this->app->scoped(WeeklySummaryLinks::class, static fn (): WeeklySummaryLinks => new SignedWeeklySummaryLinks);
    }

    public function boot(): void
    {
        Event::listen(ShiftLocked::class, [RebuildAfterShiftLocked::class, 'handle']);
        foreach (self::TOUCHES_TODAY as $event) {
            Event::listen($event, [TouchToday::class, 'handle']);
        }
        $this->loadRoutesFrom(base_path('routes/modules/analytics.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildCommand::class, WeeklySummaryCommand::class]);
        }
    }
}
