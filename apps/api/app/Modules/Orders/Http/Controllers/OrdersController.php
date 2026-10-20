<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Actions\CancelOrder;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\ReplaceLines;
use App\Modules\Orders\Actions\SendOrder;
use App\Modules\Orders\Actions\VoidOrder;
use App\Modules\Orders\Exceptions\SendRefused;
use App\Modules\Orders\Http\Requests\CancelOrderRequest;
use App\Modules\Orders\Http\Requests\ListOrdersRequest;
use App\Modules\Orders\Http\Requests\ReplaceLinesRequest;
use App\Modules\Orders\Http\Requests\StoreOrderRequest;
use App\Modules\Orders\Http\Requests\VoidOrderRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderAccess;
use App\Modules\Orders\Support\OrderList;
use App\Modules\Orders\Support\OrderListQuery;
use App\Shared\Http\ApiError;
use App\Shared\Live\ConditionalPoll;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
 *   POST /orders/{order}/void    200 the voided order (step 1.3d; orders.void_request, approved by a manager's or the
 *                                admin's PIN); 403 identity.pin.rejected, 423 identity.short_code.locked,
 *                                409 orders.order.paid / orders.order.not_voidable
 *
 * The read models (step 1.5; orders.create, read only, no Idempotency-Key):
 *   GET  /orders                 200 {data: [Order], cursor, server_time} + ETag, or 304 (OrderList: a waiter sees only the
 *                                orders they own; ?mine=1 narrows anyone to their own; ?unpaid, ?status, ?dining_table_id,
 *                                ?since=<cursor> as a poll over order_events)
 *   GET  /orders/{order}         200 the Order with its `events` timeline; 404 for another branch's order and for a
 *                                waiter's neighbour's
 */
final class OrdersController
{
    public function __construct(private readonly OrderAccess $access) {}

    public function index(ListOrdersRequest $request, OrderList $list): SymfonyResponse
    {
        $person = $this->access->person();
        $query = new OrderListQuery(
            $person->id,
            $request->wantsMine() || ! $this->access->seesEveryOrder($person),
            $request->status(),
            $request->wantsUnpaid(),
            $request->diningTableId(),
            $request->since(),
            $request->limit(),
        );
        $now = CarbonImmutable::now();
        $stamp = $list->stamp($query, $now);

        return ConditionalPoll::respond($request, $stamp['etag'], fn (): array => $list->page($query, $stamp['next'], $now, $request));
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->access->assertMayRead($order);

        return response()->json((new OrderResource(OrderResource::load($order)->load('events')))->resolve($request));
    }

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

    /**
     * The asker needs orders.void_request (the route checked the account, OrderAccess the person acting by short code);
     * the state is checked first, so a refused order does not spend a PIN attempt; then the PIN (a manager's or the
     * admin's) is verified, then the void is one transaction.
     */
    public function void(VoidOrderRequest $request, Order $order, VoidOrder $void): JsonResponse
    {
        $requester = $this->access->voidRequester();
        $void->assertVoidable($order);
        $approver = $this->access->voidApprover($request->pin());

        return $this->answer($request, $void->handle($order, $requester, $approver, $request->reason()), 200);
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
