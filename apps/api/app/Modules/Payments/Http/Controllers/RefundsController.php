<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Orders\Contracts\Orders;
use App\Modules\Orders\Contracts\RefundMovementView;
use App\Modules\Payments\Actions\RefundPayments;
use App\Modules\Payments\Http\Requests\RefundOrderRequest;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Support\PaymentAccess;
use App\Modules\Payments\Support\RefundOutcome;
use Illuminate\Http\JsonResponse;

/**
 * POST /orders/{order}/refund (phase-4 plan section 4.4, step 4.3d): orders.void_request (admin, manager, cashier) asks, the PIN of a
 * payments.void holder approves (10.2 R-N), as a void of a payment: the asker is the person acting by short code, else the account (403
 * auth.forbidden without orders.void_request); the refund's checks come first (Orders': 404 for an order the branch does not have, 409
 * orders.refund.not_refundable / line_refunded / window_closed / exceeds_paid / client_uuid_reused, 422 orders.refund.line_invalid /
 * line_not_refundable / partial_not_allowed; Payments': 422 payments.refund.allocation_invalid / proof_invalid), so a refused refund spends
 * no PIN attempt; then the PIN (403 identity.pin.rejected, 423 identity.short_code.locked), outside the transaction so a wrong one counts
 * toward the lockout; then the refund (Actions\RefundPayments), one transaction, where 409 payments.shift.required, 409
 * inventory.shift.required and 422 payments.refund.reference_required can still refuse it.
 *
 * 201 {refund: Orders' refund (id, amount, is_full, reason, lines with their dispositions), payments: [the money rows], movements: [{id,
 * item_id, location_id, qty, reverses}], order: {id, status, total, paid, balance, refunded, net_paid}}; a refund already made under the
 * same client_uuid answers 200 with the same body. The PIN is never stored, logged or audited (the Idempotency fingerprint leaves it out).
 */
final class RefundsController
{
    public function __construct(
        private readonly PaymentAccess $access,
        private readonly Orders $orders,
    ) {}

    public function store(RefundOrderRequest $request, RefundPayments $refunds, int $order): JsonResponse
    {
        $requester = $this->access->person('orders.void_request');
        $refunds->assertRefundable($order, $request->lines(), $request->clientUuid(), $request->allocation(), $request->proofFileId());
        $approver = $this->access->voidApprover($request->pin());

        $outcome = $refunds->handle(
            $order,
            $requester,
            $approver,
            $request->reason(),
            $request->lines(),
            $request->clientUuid(),
            $request->allocation(),
            $request->reference(),
            $request->proofFileId(),
        );

        return response()->json($this->present($order, $outcome), $outcome->created ? 201 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(int $orderId, RefundOutcome $outcome): array
    {
        $order = $this->orders->forPayment($orderId);

        return [
            'refund' => $outcome->refund->toArray(),
            'payments' => array_map(static fn (PaymentRefund $r): array => [
                'id' => $r->id,
                'payment_id' => $r->payment_id,
                'channel_code' => $r->channel->code,
                'channel_name_en' => $r->channel->name_en,
                'channel_name_am' => $r->channel->name_am,
                'amount' => $r->amount->amount(),
                'reference_no' => $r->reference_no,
                'proof_file_id' => $r->proof_file_id,
                'shift_instance_id' => $r->shift_instance_id,
                'refunded_at' => $r->refunded_at->toIso8601String(),
            ], $outcome->rows),
            'movements' => array_map(static fn (RefundMovementView $m): array => $m->toArray(), $this->orders->refundMovements($outcome->refund)),
            'order' => PaymentResource::order($order) + ['refunded' => $order->totals->refunded, 'net_paid' => $order->totals->netPaid],
        ];
    }
}
