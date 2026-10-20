<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Movement;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Support\LineWork;
use App\Shared\Events\LineReady;
use Carbon\CarbonImmutable;

/**
 * POST /order-lines/{line}/ready (phase-1 plan section 4; rule 3; ADR 0008: consumption is recorded at READY, not at
 * send). queued|accepted -> ready (a station may ready a line it never accepted: the KDS "All ready"), as one
 * AuditedAction (order_line.ready, LineWork) that in the SAME transaction turns the line's reservation into
 * production_consume through Reservations::consume: one row per ingredient carrying the line's ref, on_hand and
 * reserved both down by what was reserved (Phase 1 has no screen for actual use, so what was reserved is what was
 * used). Exactly once: the line row is locked and moves through its machine first (ready -> ready is refused, 409), and
 * Inventory settles a ref once whatever happens (a second consume writes nothing). prepared_by is the person who made
 * it, ready_at now. A display-case line is ready at send and never comes here (409). LineReady after the commit.
 */
final class MarkLineReady
{
    public function __construct(
        private readonly LineWork $work,
        private readonly Reservations $reservations,
    ) {}

    public function handle(OrderLine $line, User $person): OrderLine
    {
        $line = $this->work->run($line, 'order_line.ready', OrderEventType::LineReady, function (OrderLine $line) use ($person): array {
            $line->status = $line->status->transitionTo(LineStatus::Ready);
            $line->prepared_by_user_id = $person->id;
            $line->ready_at = CarbonImmutable::now()->startOfSecond();
            $consumed = $this->reservations->consume($line->reservation_ref);

            return [
                'by_user_id' => $person->id,
                'movement_ids' => array_map(static fn (Movement $m): int => $m->id, $consumed),
            ];
        });
        /** @var Order $order */
        $order = $line->order;
        event(new LineReady($line->branch_id, $line->id, $line->order_id, $line->station_id, $person->id, $order->owner_user_id));

        return $line;
    }
}
