<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\RejectReason;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Support\LineWork;
use App\Shared\Events\LineRejected;
use Carbon\CarbonImmutable;

/**
 * POST /order-lines/{line}/reject (phase-1 plan section 4 and section 10; ADR 0008: reject releases). queued|accepted
 * -> rejected with a reason of the fixed list (and the note `other` needs), as one AuditedAction (order_line.reject,
 * LineWork) that in the same transaction releases the line's reservation through Reservations::release, so what it
 * held is available again; nothing is consumed. The line returns to the waiter as unfulfilled: it is no longer
 * charged (the order's totals drop), and an order whose every line is rejected is cancelled (OrderProgress).
 * LineRejected, with the reason (code, both texts, note) and the order and item names (F5), after the commit.
 */
final class RejectLine
{
    public function __construct(
        private readonly LineWork $work,
        private readonly Reservations $reservations,
    ) {}

    public function handle(OrderLine $line, User $person, RejectReason $reason, ?string $note): OrderLine
    {
        $line = $this->work->run($line, 'order_line.reject', OrderEventType::LineRejected, function (OrderLine $line) use ($person, $reason, $note): array {
            $line->status = $line->status->transitionTo(LineStatus::Rejected);
            $line->rejected_by_user_id = $person->id;
            $line->rejected_at = CarbonImmutable::now()->startOfSecond();
            $line->reject_reason = $reason;
            $line->reject_note = $note;
            $released = $this->reservations->release($line->reservation_ref);

            return [
                'reason' => $reason->value,
                'note' => $note,
                'by_user_id' => $person->id,
                'movement_ids' => array_map(static fn (Movement $m): int => $m->id, $released),
            ];
        }, ['reason' => $reason->value, 'note' => $note]);
        /** @var Order $order */
        $order = $line->order;
        $item = $line->item()->withTrashed()->firstOrFail();
        event(new LineRejected(
            $line->branch_id, $line->id, $line->order_id, $line->station_id, $person->id, $order->owner_user_id,
            $order->order_no, $order->table_code, $item->name_en, $item->name_am,
            $reason->value, $reason->message('en'), $reason->message('am'), $note,
        ));

        return $line;
    }
}
