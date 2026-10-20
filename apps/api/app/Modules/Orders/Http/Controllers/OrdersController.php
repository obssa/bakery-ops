<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\ReplaceLines;
use App\Modules\Orders\Actions\SendOrder;
use App\Modules\Orders\Exceptions\SendRefused;
use App\Modules\Orders\Http\Requests\CancelOrderRequest;
use App\Modules\Orders\Http\Requests\ReplaceLinesRequest;
use App\Modules\Orders\Http\Requests\StoreOrderRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderAccess;
use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Taking and sending orders (phase-1 plan section 4; step 1.3b). Every route: auth:sanctum, actor, permission
 * orders.create, idempotency (routes/modules/orders.php). On top of the route's permission, the person acting by short
 * code must hold it too, and an existing order is changed, sent or cancelled only by its owner or a stations.all
 * holder (OrderAccess). Every write is an AuditedAction in the action classes.
 *
 *   POST /orders                 201 the order (sent, or open with send false); 200 the same order for a client_uuid
 *                                already used; 409 orders.send.shortfall {order (open), shortfall} when the send is
 *                                refused (the cart stays open)
 *   PUT  /orders/{order}/lines   200 the open order with its new lines
 *   POST /orders/{order}/send    200 the sent order (a sent order answers itself); 409 orders.send.shortfall
 *   POST /orders/{order}/cancel  200 the cancelled order
 */
final class OrdersController
{
    public function __construct(private readonly OrderAccess $access) {}

    public function store(StoreOrderRequest $request, CreateOrder $create, SendOrder $send): JsonResponse
    {
        $owner = $this->access->person('orders.create');
        [$order, $created] = $create->handle(
            $request->clientUuid(),
            $request->orderType(),
            $request->diningTableId(),
            $request->notes(),
            $request->lines(),
            $owner,
            $this->access->createdVia(),
        );
        if (! $created) {
            return $this->answer($request, $order, 200);
        }
        if ($request->sendNow()) {
            try {
                $order = $send->send($order);
            } catch (SendRefused $refused) {
                return $this->refused($request, $order, $refused);
            }
        }

        return $this->answer($request, $order, 201);
    }

    public function replaceLines(ReplaceLinesRequest $request, Order $order, ReplaceLines $replace): JsonResponse
    {
        $this->access->assertMayChange($order);

        return $this->answer($request, $replace->handle($order, $request->lines()), 200);
    }

    public function send(Request $request, Order $order, SendOrder $send): JsonResponse
    {
        $this->access->assertMayChange($order);
        try {
            $order = $send->send($order);
        } catch (SendRefused $refused) {
            return $this->refused($request, $order, $refused);
        }

        return $this->answer($request, $order, 200);
    }

    public function cancel(CancelOrderRequest $request, Order $order, CancelOrder $cancel): JsonResponse
    {
        $this->access->assertMayChange($order);

        return $this->answer($request, $cancel->handle($order, $request->reason()), 200);
    }

    private function answer(Request $request, Order $order, int $status): JsonResponse
    {
        return response()->json((new OrderResource(OrderResource::load($order)))->resolve($request), $status);
    }

    /** 409 orders.send.shortfall: the order as it still is (open, nothing written) and the lines that are short. */
    private function refused(Request $request, Order $order, SendRefused $refused): JsonResponse
    {
        $order->refresh();

        return ApiError::json('orders.send.shortfall', 409, [
            'order' => (new OrderResource(OrderResource::load($order)))->resolve($request),
            'shortfall' => $refused->shortfall,
        ]);
    }
}
