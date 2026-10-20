<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Catalog\Contracts\RecipeDeltaLine;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Models\OrderLineModifier;
use App\Shared\Actor\ActorContext;
use Illuminate\Validation\ValidationException;

/**
 * Writes the lines of an open cart (POST /orders, PUT /orders/{order}/lines), inside the caller's AuditedAction:
 *   - add(): one order_lines row at the price now, routed and located now, with its reservation ref (UUIDv5 of its
 *     client UUID, ADR 0008) and the actor stamp, plus one order_line_modifiers row per modifier chosen, written once:
 *     the name, the price delta and the recipe delta in base units as they are now (a later Catalog edit never changes
 *     a line already written);
 *   - retotal(): the order's subtotal and total from its charged lines (OrderTotals);
 *   - assertUnused(): a line client UUID is used once per branch (ADR 0003): 422 validation.failed on the line.
 * Nothing is reserved here: the stock is held at send (SendOrder).
 */
final class CartLines
{
    public function __construct(private readonly ActorContext $actor) {}

    public function add(Order $order, LineInput $input, PricedLine $priced): OrderLine
    {
        $modifierPrice = $priced->modifierPrice($order->currency);
        $stamp = $this->actor->stamp();
        $line = new OrderLine;
        $line->forceFill([
            'branch_id' => $order->branch_id,
            'order_id' => $order->id,
            'client_uuid' => $input->clientUuid,
            'item_id' => $priced->item->id,
            'qty' => $input->qty,
            'unit_price_snapshot' => $priced->unitPrice,
            'modifier_price_snapshot' => $modifierPrice,
            'line_total' => OrderTotals::line($input->qty, $priced->unitPrice, $modifierPrice),
            'recipe_version' => null,
            'station_id' => $priced->route->stationId,
            'location_id' => $priced->route->locationId,
            'reservation_ref' => ReservationRef::forLine($order->branch_id, $input->clientUuid),
            'status' => LineStatus::Queued,
            'notes' => $input->notes,
            'created_by_user_id' => $stamp['created_by_user_id'],
            'acting_user_id' => $stamp['acting_user_id'],
            'shift_instance_id' => $stamp['shift_instance_id'],
            'device_id' => $stamp['device_id'],
            'version' => 1,
        ])->save();

        foreach ($priced->modifiers as $modifier) {
            $row = new OrderLineModifier;
            $row->forceFill([
                'branch_id' => $order->branch_id,
                'order_line_id' => $line->id,
                'modifier_id' => $modifier->id,
                'name_en' => $modifier->name->en,
                'name_am' => $modifier->name->am,
                'price_delta_snapshot' => $modifier->priceDelta,
                'recipe_delta_snapshot' => array_map(static fn (RecipeDeltaLine $d): array => [
                    'ingredient_item_id' => $d->ingredientItemId,
                    'qty' => $d->qty->toString(),
                    'unit' => $d->unit,
                ], $modifier->recipeDelta),
            ])->save();
        }

        return $line;
    }

    /** The order's money from its charged lines, read back from the database (the caller saves the order). */
    public function retotal(Order $order): OrderTotals
    {
        $charged = [];
        foreach (OrderLine::query()->where('order_id', $order->id)->orderBy('id')->get() as $line) {
            if (OrderTotals::charges($line->status)) {
                $charged[] = $line->line_total;
            }
        }
        $totals = OrderTotals::of($charged, $order->currency);
        $order->forceFill([
            'subtotal' => $totals->subtotal,
            'discount' => $totals->discount,
            'tax' => $totals->tax,
            'total' => $totals->total,
        ]);

        return $totals;
    }

    /**
     * @param  list<LineInput>  $inputs
     * @param  list<string>  $mine  client UUIDs this order may reuse: its own lines still on it
     *
     * @throws ValidationException
     */
    public function assertUnused(array $inputs, array $mine = []): void
    {
        $asked = array_values(array_diff(array_map(static fn (LineInput $l): string => $l->clientUuid, $inputs), $mine));
        if ($asked === []) {
            return;
        }
        $used = array_map(static fn (mixed $uuid): string => strtolower((string) $uuid), OrderLine::query()->whereIn('client_uuid', $asked)->pluck('client_uuid')->all());
        $errors = [];
        foreach ($inputs as $input) {
            if (in_array($input->clientUuid, $used, true)) {
                $field = "lines.{$input->index}.client_uuid";
                $message = trans('validation.unique', ['attribute' => $field]);
                $errors[$field] = [is_string($message) ? $message : "The {$field} has already been taken."];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
