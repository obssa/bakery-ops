<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Notifications\Console\StatusCommand;
use App\Modules\Notifications\Console\TelegramWebhookCommand;
use App\Modules\Notifications\Console\VapidKeysCommand;
use App\Modules\Notifications\Drivers\DriverRegistry;
use App\Modules\Notifications\Drivers\SentLog;
use App\Modules\Notifications\Listeners\ClearStockDedupe;
use App\Modules\Notifications\Listeners\NotifyBatchDone;
use App\Modules\Notifications\Listeners\NotifyChainBroken;
use App\Modules\Notifications\Listeners\NotifyCountSubmitted;
use App\Modules\Notifications\Listeners\NotifyLineReady;
use App\Modules\Notifications\Listeners\NotifyLineRejected;
use App\Modules\Notifications\Listeners\NotifyPaymentDisputed;
use App\Modules\Notifications\Listeners\NotifyProductionAssigned;
use App\Modules\Notifications\Listeners\NotifyProductionRequested;
use App\Modules\Notifications\Listeners\NotifyStockLow;
use App\Modules\Notifications\Listeners\NotifyStockOut;
use App\Modules\Notifications\Listeners\NotifyTransferDecided;
use App\Modules\Notifications\Listeners\NotifyTransferRequested;
use App\Modules\Notifications\Listeners\NotifyVarianceDecided;
use App\Shared\Events\BatchDone;
use App\Shared\Events\ChainBroken;
use App\Shared\Events\CountSubmitted;
use App\Shared\Events\LineReady;
use App\Shared\Events\LineRejected;
use App\Shared\Events\PaymentDisputed;
use App\Shared\Events\ProductionAssigned;
use App\Shared\Events\ProductionRequested;
use App\Shared\Events\StockLow;
use App\Shared\Events\StockOut;
use App\Shared\Events\StockRecovered;
use App\Shared\Events\TransferDecided;
use App\Shared\Events\TransferRequested;
use App\Shared\Events\VarianceDecided;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Notifications (layer 5): the listeners of the events other modules announce through App\Shared\Events (it imports no
 * producer, dependency-graph.md cycle 7), each queued after commit on the default queue, the inbox and endpoint routes, the
 * driver registry (step 2.5b) and its three commands. The Notifier, Dedupe, NotificationText, DeliveryPlanner and EventScope
 * are plain classes resolved per use: they carry the scoped CurrentBranch /
 * ActorContext, so a singleton would leak one request into the next under Octane (traps.md).
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped, not singleton: the registry answers from config again on every question and only caches what it built, and
        // the sent log is the CI array driver's record; neither may outlive a request or a job under Octane or a queue worker
        $this->app->scoped(DriverRegistry::class, static fn ($app): DriverRegistry => new DriverRegistry($app));
        $this->app->scoped(SentLog::class, static fn (): SentLog => new SentLog);
    }

    public function boot(): void
    {
        Event::listen(LineReady::class, [NotifyLineReady::class, 'handle']);
        Event::listen(LineRejected::class, [NotifyLineRejected::class, 'handle']);
        Event::listen(StockOut::class, [NotifyStockOut::class, 'handle']);
        Event::listen(StockLow::class, [NotifyStockLow::class, 'handle']);
        Event::listen(StockRecovered::class, [ClearStockDedupe::class, 'handle']);
        Event::listen(TransferRequested::class, [NotifyTransferRequested::class, 'handle']);
        Event::listen(TransferDecided::class, [NotifyTransferDecided::class, 'handle']);
        Event::listen(ProductionRequested::class, [NotifyProductionRequested::class, 'handle']);
        Event::listen(ProductionAssigned::class, [NotifyProductionAssigned::class, 'handle']);
        Event::listen(BatchDone::class, [NotifyBatchDone::class, 'handle']);
        Event::listen(CountSubmitted::class, [NotifyCountSubmitted::class, 'handle']);
        Event::listen(VarianceDecided::class, [NotifyVarianceDecided::class, 'handle']);
        Event::listen(ChainBroken::class, [NotifyChainBroken::class, 'handle']);
        Event::listen(PaymentDisputed::class, [NotifyPaymentDisputed::class, 'handle']);

        $this->loadRoutesFrom(base_path('routes/modules/notifications.php'));

        if ($this->app->runningInConsole()) {
            $this->commands([StatusCommand::class, TelegramWebhookCommand::class, VapidKeysCommand::class]);
        }
    }
}
