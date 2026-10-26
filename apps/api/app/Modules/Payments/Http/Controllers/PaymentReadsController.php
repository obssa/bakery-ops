<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Contracts\Payments;
use App\Modules\Payments\Http\Requests\ListPaymentsRequest;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\PaymentList;
use App\Modules\Payments\Support\PaymentScope;
use App\Modules\Payments\Support\PaymentViews;
use App\Shared\Actor\ActorContext;
use App\Shared\Live\ConditionalPoll;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The payment reads (phase-4 plan section 4.3, step 4.3b; payments.view: admin, manager, cashier). Payment = PaymentViews; the receipt's URL
 * is signed on read.
 *
 *   GET /orders/{order}/payments   200 {data: [Payment], balance}, oldest first; 404 another branch's order
 *   GET /payments                  200 {data: [Payment + order], next_cursor, server_time}, newest first, filtered (PaymentList); polled:
 *                                  the ETag is Payments::fingerprint() with the query, the viewer's scope and the age of the signed URLs
 *                                  it carries, 304 when it is current (ConditionalPoll)
 *   GET /payments/{payment}        200 Payment + order + refunds; 404 another branch's, or one outside a cashier's scope
 * A cashier sees the payments of the last 24 hours and of their own shift (PaymentScope, 10.2 R-N); an admin and a manager all of the
 * branch's.
 */
final class PaymentReadsController
{
    public function __construct(
        private readonly PaymentViews $views,
        private readonly ActorContext $actor,
    ) {}

    public function forOrder(Orders $orders, int $order): JsonResponse
    {
        $view = $orders->forPayment($order);
        $payments = Payment::query()->where('order_id', $view->orderId)->orderBy('id')->get()->all();

        return response()->json(['data' => $this->views->present(array_values($payments)), 'balance' => $view->totals->balance]);
    }

    public function index(ListPaymentsRequest $request, Payments $payments, PaymentList $list): Response
    {
        $filters = $request->filters();
        $scope = PaymentScope::of($this->viewer($request), $this->actor);
        $ttl = max(2, (int) config('files.url_ttl_seconds'));
        $etag = ConditionalPoll::etag('payments', $payments->fingerprint() + [
            'query' => json_encode($filters, JSON_THROW_ON_ERROR),
            'urls' => intdiv(now()->getTimestamp(), intdiv($ttl, 2)),
        ] + ($scope instanceof PaymentScope ? $scope->tagParts() : ['viewer' => 'all', 'since' => null]));

        return ConditionalPoll::respond($request, $etag, function () use ($list, $filters, $scope): array {
            [$rows, $next] = $list->page($filters, $scope);

            return ['data' => $this->views->present($rows, true), 'next_cursor' => $next, 'server_time' => now()->toIso8601String()];
        });
    }

    public function show(Request $request, int $payment): JsonResponse
    {
        $row = Payment::query()->find($payment);
        $scope = PaymentScope::of($this->viewer($request), $this->actor);
        if (! $row instanceof Payment || ($scope instanceof PaymentScope && ! $scope->covers($row))) {
            throw (new ModelNotFoundException)->setModel(Payment::class, [$payment]);
        }

        return response()->json($this->views->detail($row));
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ModelNotFoundException;
        }

        return $user;
    }
}
