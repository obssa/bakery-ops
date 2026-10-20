<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Contracts\Shortfall;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Exceptions\SendRefused;
use App\Modules\Orders\Exceptions\SentMeanwhile;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Support\CartLines;
use App\Modules\Orders\Support\DailyNumbers;
use App\Modules\Orders\Support\LineContext;
use App\Modules\Orders\Support\LineResolver;
use App\Modules\Orders\Support\OrderLock;
use App\Modules\Orders\Support\OrderTimeline;
use App\Modules\Orders\Support\OrderTotals;
use App\Modules\Orders\Support\PricedLine;
use App\Modules\Orders\Support\ShortfallReport;
use App\Shared\Events\OrderSent;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;

/**
 * Sends an open order (rule 3; ADR 0008; phase-1 plan section 6 item 3, "1.3b send") in ONE database transaction, the
 * AuditedAction's (order.send):
 *   1. lock the order row; an order that is no longer open is answered as it is (a sent order) or refused (cancelled,
 *      voided: 409 orders.order.not_open), and nothing is written;
 *   2. resolve every line at sent_at: still a sellable product, its price Prices::at(sent_at), its station
 *      (Routing::stationFor; none for a display-case line), its stock location (Locations::forProduct) and, for a
 *      to-order product, the active recipe's version; a refusal names the line (422);
 *   3. reserve every line in ONE Reservations::reserveMany call, one reservation ref per line (UUIDv5 of its client
 *      UUID), with the recipe deltas of the modifiers written with the line: one ascending lock pass over the balance
 *      rows, so two orders never deadlock. A Shortfall throws SendRefused: the transaction rolls back and nothing at all
 *      is written (no reservation, no number, no event, no audit row);
 *   4. take the day's number (DailyNumbers: the order_counters row of the branch's local date, locked until commit);
 *   5. snapshot every line (unit price at sent_at, line total with the modifiers' price snapshot, recipe version,
 *      station, location); a display-case line is ready at once (the cashier picks it), a station line stays queued;
 *      the order is sent, or ready when every line is a display-case line; totals from the charged lines;
 *   6. write the timeline (order.sent, line.queued per station line with its station, line.ready per display-case
 *      line, order.ready) and dispatch OrderSent, which with its broadcast companion only goes out after the commit.
 */
final class SendOrder
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly LineResolver $resolver,
        private readonly Reservations $reservations,
        private readonly DailyNumbers $numbers,
        private readonly CartLines $cart,
        private readonly OrderTimeline $timeline,
        private readonly ShortfallReport $report,
        private readonly CurrentBranch $branch,
    ) {}

    /**
     * @throws SendRefused 409 orders.send.shortfall: some line is short, nothing was written
     * @throws OrderError 409 orders.order.not_open (cancelled or voided), 422 for a line that cannot be sold any more
     */
    public function send(Order $order): Order
    {
        if ($order->status === OrderStatus::Open) {
            try {
                return $this->audit->run(fn (Order $order): Order => $this->sendOpen($order), $order, 'order.send');
            } catch (SentMeanwhile) {
                $order->refresh();
            }
        }
        if ($order->status === OrderStatus::Open || $order->status === OrderStatus::Cancelled || $order->status === OrderStatus::Voided) {
            throw OrderError::notOpen($order->status);
        }

        return $order; // already sent: the send happened, here it is
    }

    private function sendOpen(Order $order): Order
    {
        OrderLock::refresh($order);
        if ($order->status !== OrderStatus::Open) {
            throw new SentMeanwhile;
        }
        $lines = OrderLine::query()->where('order_id', $order->id)->where('status', LineStatus::Queued->value)->with('modifiers')->orderBy('id')->get();
        if ($lines->isEmpty()) {
            throw OrderError::empty();
        }
        $sentAt = CarbonImmutable::now()->startOfSecond();

        /** @var array<int, PricedLine> $priced line id => what it is at sent_at */
        $priced = [];
        /** @var array<string, list<ReserveLine>> $byRef */
        $byRef = [];
        /** @var array<string, OrderLine> $byRefLine */
        $byRefLine = [];
        foreach ($lines as $line) {
            $priced[$line->id] = $this->resolver->resolve(new LineContext($line->client_uuid, $line->item_id, null, $line->id), $sentAt, null);
            $byRef[$line->reservation_ref] = [new ReserveLine($line->item_id, $line->qty, $priced[$line->id]->route->locationId, self::recipeDeltas($line))];
            $byRefLine[$line->reservation_ref] = $line;
        }

        $reserved = $this->reservations->reserveMany($byRef);
        if ($reserved instanceof Shortfall) {
            throw new SendRefused($this->report->lines($reserved, $byRefLine));
        }

        $businessDate = $sentAt->setTimezone($this->branch->timezone())->toDateString();
        $orderNo = $this->numbers->next($order->branch_id, $businessDate);

        $stationIds = [];
        $allAtCounter = true;
        foreach ($lines as $line) {
            $route = $priced[$line->id]->route;
            $unitPrice = $priced[$line->id]->unitPrice;
            $line->forceFill([
                'unit_price_snapshot' => $unitPrice,
                'line_total' => OrderTotals::line($line->qty, $unitPrice, $line->modifier_price_snapshot),
                'recipe_version' => $route->recipeVersion,
                'station_id' => $route->stationId,
                'location_id' => $route->locationId,
                'status' => $route->isDisplayCase() ? $line->status->transitionTo(LineStatus::Ready) : $line->status,
                'ready_at' => $route->isDisplayCase() ? $sentAt : null,
                'version' => $line->version + 1,
            ])->save();
            if ($route->stationId !== null && ! in_array($route->stationId, $stationIds, true)) {
                $stationIds[] = $route->stationId;
            }
            $allAtCounter = $allAtCounter && $route->isDisplayCase();
        }

        $totals = $this->cart->retotal($order);
        $status = $order->status->transitionTo(OrderStatus::Sent);
        $order->forceFill([
            'status' => $allAtCounter ? $status->transitionTo(OrderStatus::Ready) : $status,
            'order_no' => $orderNo,
            'business_date' => $businessDate,
            'sent_at' => $sentAt,
            'version' => $order->version + 1,
        ])->save();

        $lineIds = array_values(array_map(static fn (OrderLine $line): int => $line->id, $lines->all()));
        $this->timeline->record($order, OrderEventType::OrderSent, [
            'order_no' => $orderNo,
            'business_date' => $businessDate,
            'total' => $totals->total->amount(),
            'line_ids' => $lineIds,
        ]);
        foreach ($lines as $line) {
            $event = $line->status === LineStatus::Ready ? OrderEventType::LineReady : OrderEventType::LineQueued;
            $this->timeline->record($order, $event, ['item_id' => $line->item_id, 'qty' => $line->qty->toString()], $line);
        }
        if ($allAtCounter) {
            $this->timeline->record($order, OrderEventType::OrderReady, ['order_no' => $orderNo]);
        }

        event(new OrderSent($order->branch_id, $order->id, $orderNo, $order->owner_user_id, $stationIds, $lineIds));

        return $order;
    }

    /**
     * The recipe deltas of the modifiers written with the line, per unit, in base units (order_line_modifiers'
     * snapshot), summed per ingredient: what ReserveLine applies to the recipe ("no onion", "extra chicken").
     *
     * @return array<int, Quantity>
     */
    private static function recipeDeltas(OrderLine $line): array
    {
        $deltas = [];
        foreach ($line->modifiers as $modifier) {
            foreach ($modifier->recipe_delta_snapshot as $delta) {
                $ingredientId = (int) $delta['ingredient_item_id'];
                $deltas[$ingredientId] = ($deltas[$ingredientId] ?? Quantity::zero())->plus(Quantity::of($delta['qty']));
            }
        }

        return $deltas;
    }
}
