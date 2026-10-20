<?php

declare(strict_types=1);

namespace App\Shared\Live\Broadcasts;

use App\Shared\Events\OrderLineEvent;
use App\Shared\Live\BroadcastsDomainEvent;
use App\Shared\Live\Channels;

/**
 * The companion of every line event (phase-1 plan section 5; ADR 0009): on the line's station channel (the KDS
 * fetches its tickets again) and, when the event tells the owner, on the owner's user channel (the pad's badge).
 * broadcastAs is the event's name (line_accepted, line_ready, line_rejected, line_served). SharedServiceProvider
 * dispatches it only when it has a channel: a display-case line served reaches no station, and the waiter who served
 * it needs no telling.
 */
final class OrderLineBroadcast extends BroadcastsDomainEvent
{
    /** @var list<string> */
    private readonly array $names;

    public function __construct(OrderLineEvent $event)
    {
        parent::__construct($event);
        $names = [];
        if ($event->stationId !== null) {
            $names[] = Channels::station($event->stationId);
        }
        if ($event->tellsOwner()) {
            $names[] = Channels::user($event->ownerUserId);
        }
        $this->names = $names;
    }

    public function hasAudience(): bool
    {
        return $this->names !== [];
    }

    /**
     * @return list<string>
     */
    protected function channels(): array
    {
        return $this->names;
    }
}
