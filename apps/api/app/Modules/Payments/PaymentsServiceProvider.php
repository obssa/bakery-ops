<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Modules\Payments\Console\WarmReceiptFontsCommand;
use App\Modules\Payments\Listeners\EnsureDefaultChannels;
use App\Shared\Events\BranchCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Payments (layer 4): routes, the BranchCreated listener and `receipts:fonts` (step 4.3e). The contracts (Channels, Payments) and the action are plain
 * classes resolved per use: they read the scoped CurrentBranch / ActorContext, so a singleton would leak one request
 * into the next under Octane (traps.md). Orders' side of PaymentRecorded is Orders' own listener; Payments only
 * dispatches the event.
 */
final class PaymentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(BranchCreated::class, [EnsureDefaultChannels::class, 'handle']);
        $this->loadRoutesFrom(base_path('routes/modules/payments.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([WarmReceiptFontsCommand::class]);
        }
    }
}
