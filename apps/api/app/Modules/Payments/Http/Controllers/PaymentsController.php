<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Contracts\Payments;
use App\Modules\Payments\Http\Requests\RecordPaymentRequest;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Support\PaymentAccess;
use Illuminate\Http\JsonResponse;

/**
 * The till's payment endpoint (phase-1 plan section 4; payments.take, the route's permission middleware, and the same
 * permission for the person acting by short code, PaymentAccess). The channel list moved to ChannelsController in step 4.3a.
 *
 *   POST /orders/{order}/payments   201 {payment, order}; 200 the same body for a client_uuid already recorded;
 *                                   422 payments.channel.* / payments.amount.mismatch / payments.tendered.too_low;
 *                                   409 payments.order.not_payable / payments.order.settled / payments.client_uuid.reused;
 *                                   404 another branch's order. The write is Payments::record (one AuditedAction).
 */
final class PaymentsController
{
    public function __construct(private readonly PaymentAccess $access) {}

    public function store(RecordPaymentRequest $request, Payments $payments, int $order): JsonResponse
    {
        $receiver = $this->access->receiver();

        $recorded = $payments->record(
            $order,
            $request->clientUuid(),
            $request->channelCode(),
            $request->amount(),
            $request->tenderedAmount(),
            $request->referenceNo(),
            $receiver,
        );

        return response()->json((new PaymentResource($recorded))->resolve($request), $recorded->created ? 201 : 200);
    }
}
