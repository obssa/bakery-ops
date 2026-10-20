<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Actor\ActorContext;

/**
 * Appends to the order timeline (order_events, append-only; ADR 0009: the live-update cursor). A line event carries the
 * line and its station (the KDS cursor); every event carries the order's owner (the pad and till cursor) and who did it
 * from ActorContext: the logged-in account, the person acting by short code, the station and device they did it at,
 * the shift (rule 4). Called inside the action's transaction, so a rolled-back action leaves no event.
 */
final class OrderTimeline
{
    public function __construct(private readonly ActorContext $actor) {}

    /**
     * @param  array<string, mixed>  $payload  ids, decimal strings and reason codes only
     * @param  int|null  $approvedByUserId  the owner of the manager PIN that approved it (a void), else null
     */
    public function record(Order $order, OrderEventType $event, array $payload = [], ?OrderLine $line = null, ?int $approvedByUserId = null): OrderEvent
    {
        $row = new OrderEvent;
        $row->forceFill([
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'order_line_id' => $line?->id,
            'station_id' => $line?->station_id,
            'owner_user_id' => $order->owner_user_id,
            'event' => $event,
            'user_id' => $this->actor->requireUserId(),
            'acting_user_id' => $this->actor->actingUserId(),
            'approved_by_user_id' => $approvedByUserId,
            'actor_station_id' => $this->actor->stationId(),
            'device_id' => $this->actor->deviceId(),
            'shift_instance_id' => $this->actor->shiftInstanceId(),
            'payload' => $payload,
        ])->save();

        return $row;
    }
}
