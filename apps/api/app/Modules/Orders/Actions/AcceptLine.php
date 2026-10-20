<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Support\LineWork;
use App\Shared\Events\LineAccepted;
use Carbon\CarbonImmutable;

/**
 * POST /order-lines/{line}/accept (phase-1 plan section 4; workflows-and-screens.md, "Station handling": Accept stamps
 * user and time and tells the pad who is on it). queued -> accepted, as one AuditedAction (order_line.accept, LineWork):
 * accepted_by and prepared_by are the person on it (the one acting by short code on a shared screen), accepted_at is
 * now. Nothing is written to the ledger (ADR 0008: accept moves no stock). The order follows (sent -> in_progress).
 * LineAccepted goes out after the commit.
 */
final class AcceptLine
{
    public function __construct(private readonly LineWork $work) {}

    public function handle(OrderLine $line, User $person): OrderLine
    {
        $line = $this->work->run($line, 'order_line.accept', OrderEventType::LineAccepted, static function (OrderLine $line) use ($person): array {
            $line->status = $line->status->transitionTo(LineStatus::Accepted);
            $line->accepted_by_user_id = $person->id;
            $line->accepted_at = CarbonImmutable::now()->startOfSecond();
            $line->prepared_by_user_id = $person->id;

            return ['by_user_id' => $person->id];
        });
        /** @var Order $order */
        $order = $line->order;
        event(new LineAccepted($line->branch_id, $line->id, $line->order_id, $line->station_id, $person->id, $order->owner_user_id));

        return $line;
    }
}
