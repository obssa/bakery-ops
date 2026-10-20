<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Models\OrderLineModifier;
use App\Modules\Orders\Support\CartLines;
use App\Modules\Orders\Support\LineContext;
use App\Modules\Orders\Support\LineInput;
use App\Modules\Orders\Support\LineResolver;
use App\Modules\Orders\Support\OrderLock;
use App\Modules\Orders\Support\OrderTimeline;
use App\Modules\Orders\Support\OrderTotals;
use App\Modules\Orders\Support\PricedLine;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * PUT /orders/{order}/lines: the open cart's lines become the list sent, as one AuditedAction (order.lines_replace;
 * phase-1 plan section 4). Lines are known by their client UUID:
 *   - a line sent again keeps its row, takes the new quantity and notes, and is priced and routed again now; its
 *     product and modifiers are fixed (its modifiers' snapshot was written once), so a change there is a new line
 *     under a new client UUID (422 orders.line.changed);
 *   - a line left out is voided (rule 4: nothing is deleted); a new one is added (CartLines);
 *   - a client UUID used anywhere else in the branch, or by a line already removed from this order, is refused.
 * Nothing is reserved before the send. Every line is resolved before anything is written; the order row is locked and
 * must still be open (409 orders.order.not_open).
 */
final class ReplaceLines
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly LineResolver $resolver,
        private readonly CartLines $cart,
        private readonly OrderTimeline $timeline,
    ) {}

    /**
     * @param  list<LineInput>  $inputs
     *
     * @throws OrderError
     * @throws ValidationException
     */
    public function handle(Order $order, array $inputs): Order
    {
        if ($order->status !== OrderStatus::Open) {
            throw OrderError::notOpen($order->status);
        }
        $onOrder = [];
        foreach (OrderLine::query()->where('order_id', $order->id)->with('modifiers')->orderBy('id')->get() as $line) {
            if ($line->status !== LineStatus::Voided) {
                $onOrder[strtolower($line->client_uuid)] = $line;
            }
        }
        $this->cart->assertUnused($inputs, array_keys($onOrder));

        $now = CarbonImmutable::now()->startOfSecond();
        /** @var list<array{0: LineInput, 1: OrderLine|null, 2: PricedLine}> $plan */
        $plan = [];
        foreach ($inputs as $input) {
            $kept = $onOrder[$input->clientUuid] ?? null;
            if ($kept instanceof OrderLine) {
                self::assertSameLine($kept, $input);
                $plan[] = [$input, $kept, $this->resolver->resolve($input->context(), $now, null)];
            } else {
                $plan[] = [$input, null, $this->resolver->resolve($input->context(), $now, $input->modifierIds)];
            }
        }
        $asked = array_map(static fn (LineInput $input): string => $input->clientUuid, $inputs);
        $removed = array_values(array_filter($onOrder, static fn (OrderLine $line, string $uuid): bool => ! in_array($uuid, $asked, true), ARRAY_FILTER_USE_BOTH));

        return $this->audit->run(function (Order $order) use ($plan, $removed): Order {
            OrderLock::refresh($order);
            if ($order->status !== OrderStatus::Open) {
                throw OrderError::notOpen($order->status);
            }

            $added = [];
            $updated = [];
            foreach ($plan as [$input, $kept, $priced]) {
                if ($kept === null) {
                    $added[] = $this->cart->add($order, $input, $priced)->id;

                    continue;
                }
                $kept->forceFill([
                    'qty' => $input->qty,
                    'notes' => $input->notes,
                    'unit_price_snapshot' => $priced->unitPrice,
                    'line_total' => OrderTotals::line($input->qty, $priced->unitPrice, $kept->modifier_price_snapshot),
                    'station_id' => $priced->route->stationId,
                    'location_id' => $priced->route->locationId,
                ]);
                if ($kept->isDirty()) {
                    $kept->forceFill(['version' => $kept->version + 1])->save();
                }
                $updated[] = $kept->id;
            }
            $removedIds = [];
            foreach ($removed as $line) {
                $line->forceFill(['status' => $line->status->transitionTo(LineStatus::Voided), 'version' => $line->version + 1])->save();
                $removedIds[] = $line->id;
            }

            $totals = $this->cart->retotal($order);
            $order->forceFill(['version' => $order->version + 1])->save();
            $this->timeline->record($order, OrderEventType::OrderLinesReplaced, [
                'added' => $added,
                'updated' => $updated,
                'removed' => $removedIds,
                'total' => $totals->total->amount(),
            ]);

            return $order;
        }, $order, 'order.lines_replace');
    }

    /**
     * @throws OrderError orders.line.changed (422)
     */
    private static function assertSameLine(OrderLine $kept, LineInput $input): void
    {
        $line = new LineContext($input->clientUuid, $input->itemId, $input->index, $kept->id);
        if ($kept->item_id !== $input->itemId) {
            throw OrderError::lineChanged($line, 'item_id');
        }
        $had = array_values(array_unique(array_map(static fn (OrderLineModifier $m): int => $m->modifier_id, $kept->modifiers->all())));
        $asks = array_values(array_unique($input->modifierIds));
        sort($had);
        sort($asks);
        if ($had !== $asks) {
            throw OrderError::lineChanged($line, 'modifier_ids');
        }
    }
}
