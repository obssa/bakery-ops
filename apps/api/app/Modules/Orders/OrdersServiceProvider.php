<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Modules\Orders\Listeners\ApplyPayment;
use App\Modules\Orders\Listeners\ReversePayment;
use App\Modules\Orders\Support\OrderActions;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Events\PaymentVoided;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Orders (layer 4): routes only. The actions (Actions\*) and their helpers (Support\*) are plain classes resolved per
 * use: they read the scoped CurrentBranch / ActorContext, so a singleton would leak one request into the next under
 * Octane (traps.md).
 */
final class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // the `actions` of the orders and lines of one answer (ADR 0032): one instance per request remembers the permissions it asked
        // (scoped, so Octane forgets it after the request, traps.md)
        $this->app->scoped(OrderActions::class);
    }

    public function boot(): void
    {
        // Payments' event, applied synchronously inside the payment's transaction (the listener is Orders' own)
        Event::listen(PaymentRecorded::class, [ApplyPayment::class, 'handle']);
        // step 4.3c: a void of a payment, taken back out synchronously inside the void's transaction
        Event::listen(PaymentVoided::class, [ReversePayment::class, 'handle']);
        $this->loadRoutesFrom(base_path('routes/modules/orders.php'));
    }
}
