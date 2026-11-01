<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Support\LineContext;
use App\Shared\DomainRefusal;
use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal Orders makes, as a stable message_key (lang/<locale>/orders.php, rule 9) plus the HTTP
 * status it maps to and what it is about (the line, the field, the order's status). Thrown inside an AuditedAction it
 * rolls the whole write back; Laravel renders it through render().
 */
final class OrderError extends RuntimeException implements DomainRefusal
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        public readonly string $messageKey,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($messageKey);
    }

    /** Only an open order changes, is sent or is cancelled; a sent one is voided (step 1.3d). */
    public static function notOpen(OrderStatus $status): self
    {
        return new self('orders.order.not_open', 409, ['status' => $status->value]);
    }

    /** Only a sent order that is not paid is voided: an open cart is cancelled, a cancelled or voided one is over. */
    public static function notVoidable(OrderStatus $status): self
    {
        return new self('orders.order.not_voidable', 409, ['status' => $status->value]);
    }

    /**
     * Step 4.3c: a payment of an order that is paid or closed cannot be voided (ReversePayment); the money is refunded instead. The key is Payments'
     * (lang/<locale>/payments.php), because the refusal is the answer of POST /payments/{payment}/void; Orders only names it.
     */
    public static function paymentVoidOrderClosed(OrderStatus $status): self
    {
        return new self('payments.void.order_closed', 409, ['status' => $status->value]);
    }

    /** Paid, settled or closed: the money was taken, so it is a refund (Phase 4), not a void. */
    public static function paid(OrderStatus $status): self
    {
        return new self('orders.order.paid', 409, ['status' => $status->value]);
    }

    /**
     * The person acting by short code on a shared pad does not hold the permission the route asks of the logged-in
     * account (the same answer as the permission middleware's, shared lang key auth.forbidden).
     */
    public static function forbidden(): self
    {
        return new self('auth.forbidden', 403);
    }

    /** Another waiter's order: only its owner, or someone who works at every station (stations.all), changes it. */
    public static function notOwner(): self
    {
        return new self('orders.order.not_owner', 403);
    }

    /** A station works only the lines of its own station (orders.accept there), unless it works at every one. */
    public static function stationForbidden(): self
    {
        return new self('orders.station.forbidden', 403);
    }

    /** A line of an open cart is on no station's rail yet: it is accepted, made, rejected or served once sent. */
    public static function notSent(OrderStatus $status): self
    {
        return new self('orders.order.not_sent', 409, ['status' => $status->value]);
    }

    public static function empty(): self
    {
        return new self('orders.order.empty', 422);
    }

    /** Not a live, active product of this branch (an ingredient, another branch's item, an inactive or unknown one). */
    public static function itemNotSellable(LineContext $line): self
    {
        return new self('orders.item.not_sellable', 422, $line->toArray());
    }

    public static function noPrice(LineContext $line): self
    {
        return new self('orders.item.no_price', 422, $line->toArray());
    }

    /** A to-order product without an active recipe: its ingredients cannot be held (rule 3). */
    public static function noRecipe(LineContext $line): self
    {
        return new self('orders.item.no_recipe', 422, $line->toArray());
    }

    /** No station makes it (a to-order product) or no stock location holds it (Inventory Locations::forProduct). */
    public static function noLocation(LineContext $line): self
    {
        return new self('orders.item.no_location', 422, $line->toArray());
    }

    public static function modifierNotOfItem(LineContext $line, int $modifierId): self
    {
        return new self('orders.modifier.not_of_item', 422, $line->toArray('modifier_ids') + ['modifier_id' => $modifierId]);
    }

    /** A line keeps its product and modifiers under its client UUID; anything else is a new line. */
    public static function lineChanged(LineContext $line, string $field): self
    {
        return new self('orders.line.changed', 422, $line->toArray($field));
    }

    public static function tableUnknown(): self
    {
        return new self('orders.table.unknown', 422, ['field' => 'dining_table_id']);
    }

    /** A table code is used once per branch, by an inactive table too: orders snapshot it. */
    public static function tableCodeTaken(): self
    {
        return new self('orders.table.code_taken', 422, ['field' => 'code']);
    }

    // Step 4.1b: the refusals of Orders::refund (phase-4 plan sections 4.4 and 10.2, Ramin's answer to R-C of 2026-10-28)

    public static function refundReasonRequired(): self
    {
        return new self('orders.refund.reason_required', 422, ['field' => 'reason']);
    }

    /** Only a closed order is refunded (an open, sent..served, settled-but-not-served, cancelled or voided one is not), and only its refundable lines. */
    public static function refundNotRefundable(OrderStatus $status): self
    {
        return new self('orders.refund.not_refundable', 409, ['status' => $status->value]);
    }

    /** Not a charged line of this order: another order's, an unknown id, or a rejected or voided line (it was never paid for). */
    public static function refundLineInvalid(int $lineId): self
    {
        return new self('orders.refund.line_invalid', 422, ['line_id' => $lineId]);
    }

    /** The admin marked the line's product not refundable (Catalog refundable = false). */
    public static function refundLineNotRefundable(int $lineId): self
    {
        return new self('orders.refund.line_not_refundable', 422, ['line_id' => $lineId]);
    }

    /**
     * A line is refunded once.
     *
     * @param  list<int>  $lineIds
     */
    public static function refundLineRefunded(array $lineIds): self
    {
        return new self('orders.refund.line_refunded', 409, ['line_ids' => $lineIds]);
    }

    /** A refund is given within payments.refund_window_hours of the order's close (R-O). */
    public static function refundWindowClosed(int $hours): self
    {
        return new self('orders.refund.window_closed', 409, ['hours' => $hours]);
    }

    /** An order with a discount or tax is refunded whole, never in part, until those are defined (R-D). */
    public static function refundPartialNotAllowed(): self
    {
        return new self('orders.refund.partial_not_allowed', 422);
    }

    /** The money back may never exceed what the order took in (refunded_total <= paid_total). */
    public static function refundExceedsPaid(string $paidTotal, string $refundedTotal, string $amount): self
    {
        return new self('orders.refund.exceeds_paid', 409, ['paid_total' => $paidTotal, 'refunded_total' => $refundedTotal, 'amount' => $amount]);
    }

    /** The client_uuid names a refund of another order. */
    public static function refundClientUuidReused(): self
    {
        return new self('orders.refund.client_uuid_reused', 409, ['field' => 'client_uuid']);
    }

    /** The PIN that approves a refund belongs to a holder of payments.void (a manager, the admin). */
    public static function refundApproverForbidden(): self
    {
        return new self('orders.refund.approver_forbidden', 403);
    }

    /** DomainRefusal (step 5.5a): the key the info line carries instead of a stack trace. */
    public function messageKey(): string
    {
        return $this->messageKey;
    }

    /** DomainRefusal (step 5.5a): a 4xx is a refusal and is not reported; a 5xx is a failure and is. */
    public function httpStatus(): int
    {
        return $this->status;
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
