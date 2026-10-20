<?php

declare(strict_types=1);

namespace App\Shared\Live;

use App\Shared\Events\DomainEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use LogicException;

/**
 * The broadcast companion of a DomainEvent (ADR 0009 decision 5; phase-1 plan section 5). Modules react to the domain
 * event; the companion only tells subscribed screens to look again, on private channels of the registry (Channels),
 * with the event's array payload (ids and decimal strings) under the event's own name. Polling screens never need it:
 * the cursor feeds are the source of truth, and a missed message costs one fetch, never a ticket.
 *
 * broadcastWhen() is false unless a WebSocket transport is configured (LiveTransport): under BROADCAST_CONNECTION=null
 * (the live host and CI) nothing is sent and, importantly, nothing is queued for a cron-drained queue. Like the event
 * it accompanies, it is dispatched after the commit, so a change that was rolled back is never announced.
 */
abstract class BroadcastsDomainEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public function __construct(public readonly DomainEvent $event) {}

    /**
     * The bare names of the channels this event goes out on, built with Channels (Channels::station(5), ...).
     *
     * @return list<string>
     */
    abstract protected function channels(): array;

    /**
     * @return list<PrivateChannel>
     */
    final public function broadcastOn(): array
    {
        return array_map(static function (string $name): PrivateChannel {
            if (Channels::parse($name) === null) {
                throw new LogicException("{$name} is not a channel of the registry (App\\Shared\\Live\\Channels)");
            }

            return new PrivateChannel($name);
        }, $this->channels());
    }

    final public function broadcastAs(): string
    {
        return $this->event->name();
    }

    /**
     * @return array{event: string, branch_id: int, occurred_at: string, payload: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return $this->event->toArray();
    }

    final public function broadcastWhen(): bool
    {
        return LiveTransport::isOn();
    }
}
