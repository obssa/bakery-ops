<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Support\OrderTotals;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order (phase-1 plan section 4, "Order resource"): its number and business day once sent, type and table (with
 * the table code as it was), status, where it was taken, whose it is and who wrote it, the money as decimal strings
 * (totals: subtotal, discount, tax, total, paid, balance; ADR 0004), the timestamps of its life, why it was cancelled
 * or voided, its version (the live feeds merge by it) and its lines (OrderLineResource), removed ones included with
 * their status (rule 4: nothing is deleted). With the events relation loaded (GET /orders/{order}) it also carries the
 * `events` timeline.
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /**
     * The relations the resource reads, soft-deleted rows included (a product deleted since is still on the order);
     * for eager loading a whole list (Order::with) in a fixed number of queries.
     *
     * @return array<int|string, Closure|string>
     */
    public static function relations(): array
    {
        return [
            'lines.item' => fn ($q) => $q->withTrashed(),
            'lines.modifiers',
            'lines.station' => fn ($q) => $q->withTrashed(),
            'diningTable' => fn ($q) => $q->withTrashed(),
            'owner' => fn ($q) => $q->withTrashed(),
        ];
    }

    public static function load(Order $order): Order
    {
        return $order->load(self::relations());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $table = $order->diningTable;
        $owner = $order->owner;
        $totals = new OrderTotals($order->subtotal, $order->discount, $order->tax, $order->total);

        return [
            'id' => $order->id,
            'client_uuid' => $order->client_uuid,
            'order_no' => $order->order_no,
            'business_date' => $order->business_date?->toDateString(),
            'type' => $order->type->value,
            'table_code' => $order->table_code,
            'dining_table' => $table instanceof DiningTable ? [
                'id' => $table->id,
                'code' => $table->code,
                'name_en' => $table->name_en,
                'name_am' => $table->name_am,
            ] : null,
            'status' => $order->status->value,
            'created_via' => $order->created_via->value,
            'owner' => [
                'id' => $order->owner_user_id,
                'name' => $owner instanceof User ? $owner->name : null,
            ],
            'created_by_user_id' => $order->created_by_user_id,
            'acting_user_id' => $order->acting_user_id,
            'notes' => $order->notes,
            'totals' => $totals->toArray($order->paid_total),
            'currency' => $order->currency,
            'sent_at' => $order->sent_at?->toIso8601String(),
            'settled_at' => $order->settled_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'closed_at' => $order->closed_at?->toIso8601String(),
            'cancelled' => $order->cancelled_at === null ? null : [
                'reason' => $order->cancel_reason,
                'at' => $order->cancelled_at->toIso8601String(),
            ],
            'voided' => $order->voided_at === null ? null : [
                'reason' => $order->void_reason,
                'by' => $order->voided_by_user_id,
                'approved_by' => $order->void_approved_by_user_id,
                'at' => $order->voided_at->toIso8601String(),
            ],
            'version' => $order->version,
            'created_at' => $order->created_at?->toIso8601String(),
            'lines' => OrderLineResource::collection($order->lines)->resolve($request),
        ] + ($order->relationLoaded('events') ? ['events' => $this->events($order)] : []);
    }

    /**
     * The timeline of GET /orders/{order} (load events first): every order_events row, oldest first, as written.
     *
     * @return list<array<string, mixed>>
     */
    private function events(Order $order): array
    {
        return array_values(array_map(static fn (OrderEvent $e): array => [
            'id' => $e->id,
            'event' => $e->event->value,
            'order_line_id' => $e->order_line_id,
            'station_id' => $e->station_id,
            'user_id' => $e->user_id,
            'acting_user_id' => $e->acting_user_id,
            'approved_by_user_id' => $e->approved_by_user_id,
            'payload' => $e->payload,
            'at' => $e->created_at->toIso8601String(),
        ], $order->events->all()));
    }
}
