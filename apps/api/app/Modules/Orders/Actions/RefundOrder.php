<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\RefundPolicy;
use App\Modules\Catalog\Contracts\RefundRule;
use App\Modules\Identity\Contracts\Access;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Contracts\Reversals;
use App\Modules\Orders\Contracts\OrderRefundLineView;
use App\Modules\Orders\Contracts\OrderRefundView;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\RefundDisposition;
use App\Modules\Orders\Exceptions\OrderError;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Models\OrderRefund;
use App\Modules\Orders\Models\OrderRefundLine;
use App\Modules\Orders\Support\OrderAccess;
use App\Modules\Orders\Support\OrderLock;
use App\Modules\Orders\Support\OrderTimeline;
use App\Modules\Orders\Support\OrderTotals;
use App\Shared\Actor\ActorContext;
use App\Shared\Events\OrderRefunded;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Orders' side of a refund (phase-4 plan sections 4.4 and 10.4, step 4.1b; Ramin's answer to R-C, 2026-10-28), behind Orders::refund. The
 * asker is the person acting by short code, else the account, and holds orders.void_request; the approver is the owner of the manager PIN
 * the caller verified first (step 4.3d's endpoint, before any transaction, so a wrong PIN counts toward the lockout) and holds
 * payments.void. The PIN never reaches Orders. One transaction, joined to the caller's (Payments writes its payment_refunds rows in the
 * same one), under the order's row lock, then the lines' (FOR UPDATE, id order), then the Ledger's (shift rows, balances ascending):
 *   - checked, in this order, again under the locks: a reason; a refund already made under this client_uuid answers it as it is (another
 *     order's is refused); the order is closed (R-O: within payments.refund_window_hours of its close); the lines named are charged lines
 *     of the order, their products refundable (Catalog RefundPolicy, read now), none refunded already; no lines named means every
 *     refundable charged line, none at all is not_refundable; an order with a discount or tax goes back whole or not at all (R-D);
 *     refunded_total stays at or below paid_total;
 *   - the money back is the lines' line_total, the order's total when every charged line goes back at once; is_full says whether every
 *     charged line has now come back;
 *   - each line takes its disposition from its product's settings at this moment: restock when refund_restock is on, the product still
 *     exists (the ledger takes live items only) and the line took the product itself out of stock (its sale at the display case, or the
 *     product used at a station: Reversals::consumptionsOf its reservation); waste otherwise (a product made to order, whose ingredients
 *     are used; a deleted product; a product made to order when it was sold). The restocked movements are reversed with ONE
 *     Reversals::of (a `return` each, pointing at the original; the shift rule of the place holds: inventory.shift.required);
 *   - written: order_refunds and order_refund_lines (the disposition stays as written whatever the settings become), order_lines.refunded_at,
 *     orders.refunded_total / refunded_at and the version; line.refunded per line and order.refunded on the timeline with the approver;
 *     OrderRefunded, delivered after the commit. The order stays closed (OrderStatus does not change).
 * Every refusal is an OrderError (or the Ledger's InventoryError) and writes nothing.
 */
final class RefundOrder
{
    public function __construct(
        private readonly OrderAccess $access,
        private readonly Access $identity,
        private readonly OrderTimeline $timeline,
        private readonly RefundPolicy $policy,
        private readonly Items $items,
        private readonly Reversals $reversals,
        private readonly ActorContext $actor,
        private readonly ConnectionResolverInterface $db,
    ) {}

    /**
     * The checks a caller makes before it asks for the PIN (so a refusal spends no attempt): the same as handle()'s on the order as it is
     * now, writing nothing. A refund already made under $clientUuid passes (handle() answers it).
     *
     * @param  list<int>  $lineIds
     *
     * @throws OrderError
     * @throws ModelNotFoundException
     */
    public function assertRefundable(int $orderId, array $lineIds, ?string $clientUuid = null): void
    {
        $order = Order::query()->findOrFail($orderId);
        if ($clientUuid !== null && $this->existing($order, $clientUuid) !== null) {
            return;
        }
        $this->plan($order, $this->linesOf($order, false), $lineIds);
    }

    /**
     * @param  list<int>  $lineIds  order-line ids; empty: every refundable line
     *
     * @throws OrderError
     * @throws ModelNotFoundException
     */
    public function handle(int $orderId, string $reason, User $approver, array $lineIds, string $clientUuid): OrderRefundView
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw OrderError::refundReasonRequired();
        }
        if (! Str::isUuid($clientUuid)) {
            throw new InvalidArgumentException('A refund client UUID is a UUID.');
        }
        $requester = $this->access->person('orders.void_request');
        if (! $this->identity->can($approver, 'payments.void')) {
            throw OrderError::refundApproverForbidden();
        }
        $order = Order::query()->findOrFail($orderId);

        /** @var OrderRefundView $view */
        $view = $this->connection()->transaction(function () use ($order, $reason, $approver, $lineIds, $clientUuid, $requester): OrderRefundView {
            OrderLock::refresh($order);
            $existing = $this->existing($order, $clientUuid);
            if ($existing !== null) {
                return self::view($existing);
            }
            $lines = $this->linesOf($order, true);
            $plan = $this->plan($order, $lines, $lineIds);

            return $this->write($order, $lines, $plan, $reason, $requester, $approver, $clientUuid);
        });

        return $view;
    }

    /**
     * What the refund will be, or why not; reads only.
     *
     * @param  Collection<int, OrderLine>  $lines  every line of the order, in id order
     * @param  list<int>  $lineIds
     * @return array{lines: list<OrderLine>, rules: array<int, RefundRule>, amount: Money, is_full: bool}
     *
     * @throws OrderError
     */
    private function plan(Order $order, Collection $lines, array $lineIds): array
    {
        if ($order->status !== OrderStatus::Closed) {
            throw OrderError::refundNotRefundable($order->status);
        }
        $hours = (int) config('payments.refund_window_hours', 72);
        $closedAt = $order->closed_at ?? $order->paid_at;
        if ($closedAt !== null && CarbonImmutable::now()->isAfter($closedAt->addHours($hours))) {
            throw OrderError::refundWindowClosed($hours);
        }

        /** @var array<int, OrderLine> $charged */
        $charged = [];
        foreach ($lines as $line) {
            if (OrderTotals::charges($line->status)) {
                $charged[$line->id] = $line;
            }
        }
        $rules = $this->policy->for(array_values(array_unique(array_map(static fn (OrderLine $l): int => $l->item_id, $charged))));
        $refundable = static fn (OrderLine $line): bool => ($rules[$line->item_id] ?? null)?->refundable === true;

        $selected = [];
        if ($lineIds !== []) {
            foreach (array_values(array_unique(array_map('intval', $lineIds))) as $id) {
                $line = $charged[$id] ?? throw OrderError::refundLineInvalid($id);
                if (! $refundable($line)) {
                    throw OrderError::refundLineNotRefundable($id);
                }
                $selected[$id] = $line;
            }
            ksort($selected);
        } else {
            $selected = array_filter($charged, $refundable);
            if ($selected === []) {
                throw OrderError::refundNotRefundable($order->status);
            }
        }

        $already = array_values(array_map(static fn (OrderLine $l): int => $l->id, array_filter($selected, static fn (OrderLine $l): bool => $l->refunded_at !== null)));
        if ($already !== []) {
            throw OrderError::refundLineRefunded($already);
        }

        $whole = array_keys($selected) === array_keys($charged);
        if (! $whole && ($order->discount->isPositive() || $order->tax->isPositive())) {
            throw OrderError::refundPartialNotAllowed();
        }
        $amount = Money::zero($order->currency);
        foreach ($selected as $line) {
            $amount = $amount->plus($line->line_total);
        }
        if ($whole) {
            $amount = $order->total;
        }
        if (! $amount->isPositive()) {
            throw OrderError::refundNotRefundable($order->status);
        }
        if ($order->refunded_total->plus($amount)->isGreaterThan($order->paid_total)) {
            throw OrderError::refundExceedsPaid($order->paid_total->amount(), $order->refunded_total->amount(), $amount->amount());
        }
        $isFull = array_filter($charged, static fn (OrderLine $l): bool => $l->refunded_at === null && ! isset($selected[$l->id])) === [];

        return ['lines' => array_values($selected), 'rules' => $rules, 'amount' => $amount, 'is_full' => $isFull];
    }

    /**
     * @param  Collection<int, OrderLine>  $lines
     * @param  array{lines: list<OrderLine>, rules: array<int, RefundRule>, amount: Money, is_full: bool}  $plan
     */
    private function write(Order $order, Collection $lines, array $plan, string $reason, User $requester, User $approver, string $clientUuid): OrderRefundView
    {
        // what goes back into stock: the line's own consumptions of its product, when its product goes back and still exists
        $deleted = array_flip($this->items->deleted(array_values(array_unique(array_map(static fn (OrderLine $l): int => $l->item_id, $plan['lines'])))));
        $originals = [];
        foreach ($plan['lines'] as $line) {
            $originals[$line->id] = [];
            if (($plan['rules'][$line->item_id] ?? null)?->restock !== true || isset($deleted[$line->item_id])) {
                continue;
            }
            foreach ($this->reversals->consumptionsOf($line->reservation_ref, $line->item_id) as $movement) {
                $originals[$line->id][] = $movement->id;
            }
        }
        $ids = array_merge(...array_values($originals));
        $returnOf = [];
        foreach ($this->reversals->of($ids) as $return) {
            $returnOf[(int) $return->refId] = $return;
        }

        $now = CarbonImmutable::now()->startOfSecond();
        $refund = new OrderRefund;
        $refund->forceFill([
            'branch_id' => $order->branch_id,
            'client_uuid' => strtolower($clientUuid),
            'order_id' => $order->id,
            'reason' => $reason,
            'amount' => $plan['amount'],
            'is_full' => $plan['is_full'],
            'requested_by_user_id' => $requester->id,
            'approved_by_user_id' => $approver->id,
            'acting_user_id' => $this->actor->actingUserId(),
            'shift_instance_id' => $this->actor->shiftInstanceId(),
            'station_id' => $this->actor->stationId(),
            'device_id' => $this->actor->deviceId(),
            'created_at' => $now,
        ])->save();

        $views = [];
        foreach ($plan['lines'] as $line) {
            $movementIds = array_map(static fn (int $id): int => ($returnOf[$id] ?? throw new LogicException("movement {$id} was not reversed"))->id, $originals[$line->id]);
            $disposition = $movementIds === [] ? RefundDisposition::Waste : RefundDisposition::Restock;
            $row = new OrderRefundLine;
            $row->forceFill([
                'branch_id' => $order->branch_id,
                'order_refund_id' => $refund->id,
                'order_line_id' => $line->id,
                'qty' => $line->qty,
                'line_amount' => $line->line_total,
                'disposition' => $disposition,
                'movement_ids' => $movementIds,
                'created_at' => $now,
            ])->save();
            $line->forceFill(['refunded_at' => $now, 'version' => $line->version + 1])->save();
            $this->timeline->record($order, OrderEventType::LineRefunded, [
                'refund_id' => $refund->id,
                'qty' => $line->qty->toString(),
                'amount' => $line->line_total->amount(),
                'disposition' => $disposition->value,
                'restock' => ($plan['rules'][$line->item_id] ?? null)?->restock === true,
                'movement_ids' => $movementIds,
            ], $line, $approver->id, false);
            $views[] = new OrderRefundLineView($line->id, $line->item_id, $line->qty->toString(), $line->line_total->amount(), $disposition->value, $movementIds);
        }

        $order->refunded_total = $order->refunded_total->plus($plan['amount']);
        $order->refunded_at = $now;
        $order->version = $order->version + 1;
        $order->save();
        $lineIds = array_map(static fn (OrderRefundLineView $v): int => $v->lineId, $views);
        $this->timeline->record($order, OrderEventType::OrderRefunded, [
            'refund_id' => $refund->id,
            'amount' => $plan['amount']->amount(),
            'is_full' => $plan['is_full'],
            'reason' => $reason,
            'line_ids' => $lineIds,
            'by_user_id' => $requester->id,
            'approved_by_user_id' => $approver->id,
        ], null, $approver->id);

        $view = new OrderRefundView($refund->id, $order->id, $plan['amount']->amount(), $plan['is_full'], $reason, $requester->id, $approver->id, $views);
        event(new OrderRefunded($order->branch_id, $order->id, $refund->id, $view->amount, $view->isFull, $lineIds, $view->movementIds(), $requester->id, $approver->id, $reason, $now));

        return $view;
    }

    /**
     * The refund already made under $clientUuid in this branch: its view when it is this order's, refused when it is another order's.
     *
     * @throws OrderError orders.refund.client_uuid_reused (409)
     */
    private function existing(Order $order, string $clientUuid): ?OrderRefund
    {
        $refund = OrderRefund::query()->where('client_uuid', strtolower($clientUuid))->with('lines')->first();
        if ($refund !== null && $refund->order_id !== $order->id) {
            throw OrderError::refundClientUuidReused();
        }

        return $refund;
    }

    private static function view(OrderRefund $refund): OrderRefundView
    {
        $lines = [];
        foreach ($refund->lines as $line) {
            $lines[] = new OrderRefundLineView(
                $line->order_line_id,
                (int) OrderLine::query()->whereKey($line->order_line_id)->value('item_id'),
                $line->qty->toString(),
                $line->line_amount->amount(),
                $line->disposition->value,
                array_map('intval', $line->movement_ids),
            );
        }

        return new OrderRefundView($refund->id, $refund->order_id, $refund->amount->amount(), $refund->is_full, $refund->reason, $refund->requested_by_user_id, $refund->approved_by_user_id, $lines);
    }

    /**
     * @return Collection<int, OrderLine>
     */
    private function linesOf(Order $order, bool $lock): Collection
    {
        $query = OrderLine::query()->where('order_id', $order->id)->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    private function connection(): Connection
    {
        $connection = $this->db->connection();
        if (! $connection instanceof Connection) {
            throw new LogicException('Orders needs a database connection.');
        }

        return $connection;
    }
}
