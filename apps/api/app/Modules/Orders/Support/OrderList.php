<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Live\OverlapCursor;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The order list behind GET /orders (phase-1 plan section 4, step 1.5, ADR 0009). The feed is the branch's append-only
 * order_events (narrowed to the person's own orders when $mine), read through OverlapCursor:
 *
 *  - first load (no cursor): the orders matching the filters, newest first, at most $limit;
 *  - poll (?since=): every order with an event after the cursor, newest first. The status and unpaid filters are NOT
 *    applied to a poll: an order that stopped matching (paid, cancelled, voided) must still arrive once so the client
 *    drops it; the client merges by id and version and applies the filters itself. mine and dining_table_id are
 *    properties of an order that never change, so they do apply;
 *  - the cursor handed back is the highest event id at least `overlap_seconds` old, never below the one sent.
 *
 * Every change to an order appends an event, so the feed's fingerprint is the ETag of both modes: a 304 never builds
 * the page (one indexed query set), and it covers the unsettled tail so a late commit is never answered 304.
 */
final class OrderList
{
    public const string ETAG_SCOPE = 'orders';

    /** The statuses of ?status=active: everything not finished (paid is the instant between settling and closing). */
    public const array ACTIVE = [
        OrderStatus::Open, OrderStatus::Sent, OrderStatus::InProgress, OrderStatus::Ready, OrderStatus::Served, OrderStatus::Paid,
    ];

    /** ?unpaid=1: sent to the stations and not yet settled. */
    public const array UNPAID = [OrderStatus::Sent, OrderStatus::InProgress, OrderStatus::Ready, OrderStatus::Served];

    public const int DEFAULT_LIMIT = 100;

    public const int MAX_LIMIT = 200;

    public function __construct(private readonly CurrentBranch $branch) {}

    /**
     * The ETag and the pieces of the page, computed from the fingerprint alone.
     *
     * @return array{etag: string, since: int|null, next: int}
     */
    public function stamp(OrderListQuery $q, CarbonImmutable $now): array
    {
        $window = OverlapCursor::fromConfig();
        $feed = $this->feed($q);
        $fingerprint = $window->fingerprint($feed, $q->since, $now);

        $etag = ConditionalPoll::etag(self::ETAG_SCOPE, [
            'branch' => $this->branch->id(),
            'mine' => $q->mine ? $q->personId : 0,
            'status' => $q->unpaid ? 'unpaid' : ($q->status === null ? 'active' : $q->status->value),
            'table' => $q->diningTableId,
            'limit' => $q->limit,
        ] + $fingerprint);

        return ['etag' => $etag, 'since' => $q->since, 'next' => $fingerprint['next']];
    }

    /**
     * @return array{data: list<array<string, mixed>>, cursor: string, server_time: string}
     */
    public function page(OrderListQuery $q, int $next, CarbonImmutable $now, Request $request): array
    {
        $orders = Order::query()
            ->when($q->mine, fn (Builder $b) => $b->where('owner_user_id', $q->personId))
            ->when($q->diningTableId !== null, fn (Builder $b) => $b->where('dining_table_id', $q->diningTableId))
            ->with(OrderResource::relations());

        if ($q->since === null) {
            $statuses = $q->unpaid ? self::UNPAID : ($q->status === null ? self::ACTIVE : [$q->status]);
            $orders->whereIn('status', array_map(static fn (OrderStatus $s): string => $s->value, $statuses))
                ->orderByDesc('id')->limit($q->limit);
        } else {
            $ids = OverlapCursor::fromConfig()->after($this->feed($q), $q->since)->pluck('order_id')->all();
            $orders->whereIn('id', array_values(array_unique($ids)))->orderByDesc('id');
        }

        return [
            'data' => array_values(OrderResource::collection($orders->get())->resolve($request)),
            'cursor' => (string) $next,
            'server_time' => $now->toIso8601String(),
        ];
    }

    /**
     * @return Builder<OrderEvent>
     */
    private function feed(OrderListQuery $q): Builder
    {
        return OrderEvent::query()->when($q->mine, fn (Builder $b) => $b->where('owner_user_id', $q->personId));
    }
}
