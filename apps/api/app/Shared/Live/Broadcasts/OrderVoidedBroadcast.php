<?php

declare(strict_types=1);

namespace App\Shared\Live\Broadcasts;

use App\Shared\Events\OrderVoided;
use App\Shared\Live\BroadcastsDomainEvent;
use App\Shared\Live\Channels;

/**
 * OrderVoided on the channel of every station that had one of the voided lines (their tickets leave the rail) and on
 * the owner's user channel (the pad shows the order voided); phase-1 plan section 5, ADR 0009. Dispatched by
 * SharedServiceProvider after the commit; inert while broadcasting is off.
 */
final class OrderVoidedBroadcast extends BroadcastsDomainEvent
{
    /** @var list<string> */
    private readonly array $names;

    public function __construct(OrderVoided $event)
    {
        parent::__construct($event);
        $this->names = [
            ...array_map(static fn (int $stationId): string => Channels::station($stationId), $event->stationIds),
            Channels::user($event->ownerUserId),
        ];
    }

    /**
     * @return list<string>
     */
    protected function channels(): array
    {
        return $this->names;
    }
}
