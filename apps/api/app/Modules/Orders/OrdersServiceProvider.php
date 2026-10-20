<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Modules\Orders\Listeners\ApplyPayment;
use App\Shared\Events\PaymentRecorded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Orders (layer 4): routes only. The actions (Actions\*) and their helpers (Support\*) are plain classes resolved per
 * use: they read the scoped CurrentBranch / ActorContext, so a singleton would leak one request into the next under
 * Octane (traps.md).
 */
final class OrdersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Payments' event, applied synchronously inside the payment's transaction (the listener is Orders' own)
        Event::listen(PaymentRecorded::class, [ApplyPayment::class, 'handle']);
        $this->loadRoutesFrom(base_path('routes/modules/orders.php'));
    }
}
