<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Notifications\Listeners\ClearStockDedupe;
use App\Modules\Notifications\Listeners\NotifyLineReady;
use App\Modules\Notifications\Listeners\NotifyLineRejected;
use App\Modules\Notifications\Listeners\NotifyStockOut;
use App\Modules\Notifications\Listeners\NotifyTransferDecided;
use App\Modules\Notifications\Listeners\NotifyTransferRequested;
use App\Shared\Events\LineReady;
use App\Shared\Events\LineRejected;
use App\Shared\Events\StockOut;
use App\Shared\Events\StockRecovered;
use App\Shared\Events\TransferDecided;
use App\Shared\Events\TransferRequested;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Notifications (layer 5): the listeners of the events other modules announce through App\Shared\Events (it imports no
 * producer, dependency-graph.md cycle 7), each queued after commit on the default queue, and the inbox routes. The Notifier,
 * Dedupe, NotificationText and EventScope are plain classes resolved per use: they carry the scoped CurrentBranch /
 * ActorContext, so a singleton would leak one request into the next under Octane (traps.md).
 */
final class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(LineReady::class, [NotifyLineReady::class, 'handle']);
        Event::listen(LineRejected::class, [NotifyLineRejected::class, 'handle']);
        Event::listen(StockOut::class, [NotifyStockOut::class, 'handle']);
        Event::listen(StockRecovered::class, [ClearStockDedupe::class, 'handle']);
        Event::listen(TransferRequested::class, [NotifyTransferRequested::class, 'handle']);
        Event::listen(TransferDecided::class, [NotifyTransferDecided::class, 'handle']);

        $this->loadRoutesFrom(base_path('routes/modules/notifications.php'));
    }
}
