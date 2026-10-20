<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Actions\AcceptLine;
use App\Modules\Orders\Actions\MarkLineReady;
use App\Modules\Orders\Actions\RejectLine;
use App\Modules\Orders\Actions\ServeLine;
use App\Modules\Orders\Http\Requests\RejectLineRequest;
use App\Modules\Orders\Http\Resources\OrderLineResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Support\OrderAccess;
use App\Modules\Orders\Support\OrderTotals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The station actions on one order line (phase-1 plan section 4; step 1.3c). Every route: auth:sanctum, actor,
 * idempotency, and a permission (routes/modules/orders.php): orders.accept for accept / ready / reject, on top of which
 * the person doing it (the one acting by short code on a shared screen) must hold it at the line's station or work at
 * every station (OrderAccess::assertAtStation, 403 orders.station.forbidden); orders.create for served, by the order's
 * owner or a stations.all holder (403 orders.order.not_owner). {line} binds through the BranchScoped model (another
 * branch's line is 404). Each write is one AuditedAction in its action class.
 *
 *   POST /order-lines/{line}/accept   200 the line (accepted) and its order's status
 *   POST /order-lines/{line}/ready    200 the line (ready; its reservation consumed) and its order's status
 *   POST /order-lines/{line}/reject   200 the line (rejected, with the reason in both languages; its reservation
 *                                     released) and its order's status and totals
 *   POST /order-lines/{line}/served   200 the line (served) and its order's status
 * A move the line's machine does not allow is 409 state_machine.illegal_transition; a line of an unsent cart is 409
 * orders.order.not_sent.
 */
final class OrderLinesController
{
    public function __construct(private readonly OrderAccess $access) {}

    public function accept(Request $request, OrderLine $line, AcceptLine $accept): JsonResponse
    {
        $person = $this->access->assertAtStation($line);

        return $this->answer($request, $accept->handle($line, $person));
    }

    public function ready(Request $request, OrderLine $line, MarkLineReady $ready): JsonResponse
    {
        $person = $this->access->assertAtStation($line);

        return $this->answer($request, $ready->handle($line, $person));
    }

    public function reject(RejectLineRequest $request, OrderLine $line, RejectLine $reject): JsonResponse
    {
        $person = $this->access->assertAtStation($line);

        return $this->answer($request, $reject->handle($line, $person, $request->reason(), $request->note()));
    }

    public function served(Request $request, OrderLine $line, ServeLine $serve): JsonResponse
    {
        $person = $this->access->assertMayChange(Order::query()->findOrFail($line->order_id));

        return $this->answer($request, $serve->handle($line, $person));
    }

    /** The line as the pad and the KDS show it, and where its order now stands (status, version, money). */
    private function answer(Request $request, OrderLine $line): JsonResponse
    {
        $line->load([
            'item' => fn ($q) => $q->withTrashed(),
            'modifiers',
            'station' => fn ($q) => $q->withTrashed(),
        ]);
        /** @var Order $order */
        $order = $line->order;
        $totals = new OrderTotals($order->subtotal, $order->discount, $order->tax, $order->total);

        return response()->json((new OrderLineResource($line))->resolve($request) + [
            'order' => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'status' => $order->status->value,
                'version' => $order->version,
                'totals' => $totals->toArray($order->paid_total),
            ],
        ]);
    }
}
