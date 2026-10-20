<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Support\LineWork;
use App\Shared\Events\LineServed;
use Carbon\CarbonImmutable;

/**
 * POST /order-lines/{line}/served (phase-1 plan section 4; workflows-and-screens.md, "Waiter taps Served"). ready ->
 * served, only after ready, as one AuditedAction (order_line.serve, LineWork): served_by is the person who handed it
 * over (the waiter acting by short code on the shared pad), served_at now. Nothing is written to the ledger: a station
 * line was consumed at ready, a display-case line is sold at payment. The order is served once every live
 * line is (OrderProgress); one paid before is then paid, sold and closed too (Settlement, in LineWork's transaction). LineServed after the commit (the ticket leaves the station's rail).
 */
final class ServeLine
{
    public function __construct(private readonly LineWork $work) {}

    public function handle(OrderLine $line, User $person): OrderLine
    {
        $line = $this->work->run($line, 'order_line.serve', OrderEventType::LineServed, static function (OrderLine $line) use ($person): array {
            $line->status = $line->status->transitionTo(LineStatus::Served);
            $line->served_by_user_id = $person->id;
            $line->served_at = CarbonImmutable::now()->startOfSecond();

            return ['by_user_id' => $person->id];
        });
        /** @var Order $order */
        $order = $line->order;
        event(new LineServed($line->branch_id, $line->id, $line->order_id, $line->station_id, $person->id, $order->owner_user_id));

        return $line;
    }
}
