<?php

declare(strict_types=1);

namespace App\Shared;

use App\Shared\Actor\ActorContext;
use App\Shared\Events\LineAccepted;
use App\Shared\Events\LineReady;
use App\Shared\Events\LineRejected;
use App\Shared\Events\LineServed;
use App\Shared\Events\OrderLineEvent;
use App\Shared\Events\OrderPaid;
use App\Shared\Events\OrderSent;
use App\Shared\Events\OrderVoided;
use App\Shared\Events\StockChanged;
use App\Shared\Live\Broadcasts\OrderLineBroadcast;
use App\Shared\Live\Broadcasts\OrderPaidBroadcast;
use App\Shared\Live\Broadcasts\OrderSentBroadcast;
use App\Shared\Live\Broadcasts\OrderVoidedBroadcast;
use App\Shared\Live\Broadcasts\StockChangedBroadcast;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Request-scoped state lives in scoped() bindings only: Octane forgets them after every request, so one
 * user's actor or branch can never bleed into the next request (traps.md, Octane; tests/Arch/OctaneSafetyTest).
 *
 * Live updates (ADR 0009): every StockChanged gets its broadcast companion on the branch's stock channel, every
 * OrderSent that reaches a station gets one on each of its stations' channels, every line event (accepted, ready,
 * rejected, served) one on the line's station and, where it tells the owner, the owner's channel (phase-1 plan
 * section 5). The
 * companion's broadcastWhen() is false unless a WebSocket transport is configured, so under BROADCAST_CONNECTION=null
 * (the live host) the dispatch sends and queues nothing.
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentBranch::class, static fn (): CurrentBranch => new CurrentBranch);
        $this->app->scoped(ActorContext::class, static fn (): ActorContext => new ActorContext);
    }

    public function boot(): void
    {
        Event::listen(StockChanged::class, static function (StockChanged $event): void {
            event(new StockChangedBroadcast($event));
        });
        Event::listen(OrderSent::class, static function (OrderSent $event): void {
            if ($event->stationIds !== []) {
                event(new OrderSentBroadcast($event));
            }
        });
        Event::listen(OrderPaid::class, static function (OrderPaid $event): void {
            event(new OrderPaidBroadcast($event));
        });
        Event::listen(OrderVoided::class, static function (OrderVoided $event): void {
            event(new OrderVoidedBroadcast($event));
        });
        Event::listen([LineAccepted::class, LineReady::class, LineRejected::class, LineServed::class], static function (OrderLineEvent $event): void {
            $companion = new OrderLineBroadcast($event);
            if ($companion->hasAudience()) {
                event($companion);
            }
        });
    }
}
