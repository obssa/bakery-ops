<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\LineStatus;
use App\Modules\Orders\Http\Resources\OrderLineResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Live\OverlapCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rail of one station (manifest orders.provides: Tickets::since(station, cursor); ADR 0009; the HTTP endpoint
 * GET /stations/{station}/tickets is StationTicketsController). A ticket is an order with its lines at the station. The feed is the
 * append-only order_events rows of the station (station_id is the KDS cursor column), read through Shared Live
 * OverlapCursor:
 *
 *  - first load (no cursor): the orders with a line still on the rail (queued, accepted or ready) at the station;
 *  - a poll (cursor): every order with an event at the station after the cursor, with its lines at the station in
 *    their current status, so a line that was served, rejected or voided comes back once and the client drops it
 *    (clients merge by id and version, OverlapCursor::merge);
 *  - the cursor handed back is the highest event id at least `overlap_seconds` old, never below the one sent, so an
 *    event that commits late is still after the cursor on the next poll and is delivered (traps.md, live updates);
 *  - a cursor that is not a plain number is a first load.
 *
 * Tickets are oldest first, by the first event they have at the station. fingerprint() is the cheap read behind the
 * ETag, so a 304 can be answered without building the page. Another branch's events and stations are never seen.
 */
final class Tickets
{
    public const string ETAG_SCOPE = 'tickets';

    public function since(int $stationId, ?string $cursor): TicketsPage
    {
        $since = self::parse($cursor);
        $now = CarbonImmutable::now();
        $window = OverlapCursor::fromConfig();
        $feed = $this->feed($stationId);

        $next = $window->next($feed, $since, $now);
        $fingerprint = $window->fingerprint($feed, $since, $now);

        /** @var array<int, int> $first order id => the first event id the order has in this answer's feed */
        $first = [];
        if ($since === null) {
            $onRail = OrderLine::query()
                ->where('station_id', $stationId)
                ->whereIn('status', [LineStatus::Queued->value, LineStatus::Accepted->value, LineStatus::Ready->value])
                ->distinct()->pluck('order_id')->map(static fn (mixed $id): int => (int) $id)->all();
            if ($onRail !== []) {
                foreach ($this->feed($stationId)->whereIn('order_id', $onRail)->orderBy('id')->get(['id', 'order_id']) as $event) {
                    $first[$event->order_id] ??= $event->id;
                }
                foreach ($onRail as $orderId) {
                    $first[$orderId] ??= PHP_INT_MAX;
                }
            }
        } else {
            foreach ($window->after($this->feed($stationId), $since)->get(['id', 'order_id']) as $event) {
                $first[$event->order_id] ??= $event->id;
            }
        }

        return new TicketsPage($this->tickets($stationId, $first), (string) $next, $now, $fingerprint);
    }

    /**
     * The ETag of a station's rail for a fingerprint (fingerprint(), or TicketsPage::$fingerprint): the same formula
     * TicketsPage::etag uses, so GET /stations/{station}/tickets can answer 304 without building the page.
     *
     * @param  array{since: int|null, next: int, max: int|null, pending: int}  $fingerprint
     */
    public static function etag(int $stationId, array $fingerprint): string
    {
        return ConditionalPoll::etag(self::ETAG_SCOPE, ['station' => $stationId] + $fingerprint);
    }

    /**
     * What the ETag of since($stationId, $cursor) is computed from, in a few indexed queries (OverlapCursor::fingerprint).
     *
     * @return array{since: int|null, next: int, max: int|null, pending: int}
     */
    public function fingerprint(int $stationId, ?string $cursor): array
    {
        return OverlapCursor::fromConfig()->fingerprint($this->feed($stationId), self::parse($cursor), CarbonImmutable::now());
    }

    /**
     * @return Builder<OrderEvent>
     */
    private function feed(int $stationId): Builder
    {
        return OrderEvent::query()->where('station_id', $stationId);
    }

    /**
     * @param  array<int, int>  $first  order id => its first event id in the feed
     * @return list<TicketView>
     */
    private function tickets(int $stationId, array $first): array
    {
        if ($first === []) {
            return [];
        }

        $orders = Order::query()->whereIn('id', array_keys($first))->get()->keyBy('id');
        $owners = User::query()->withTrashed()->whereIn('id', $orders->pluck('owner_user_id')->unique()->all())->pluck('name', 'id');
        $lines = OrderLine::query()
            ->where('station_id', $stationId)->whereIn('order_id', array_keys($first))
            ->with([
                'item' => fn ($q) => $q->withTrashed(),
                'modifiers',
                'station' => fn ($q) => $q->withTrashed(),
            ])
            ->orderBy('id')->get()->groupBy('order_id');

        $ids = array_keys($first);
        usort($ids, static fn (int $a, int $b): int => [$first[$a], $a] <=> [$first[$b], $b]);

        $tickets = [];
        foreach ($ids as $orderId) {
            $order = $orders->get($orderId);
            if (! $order instanceof Order) {
                continue;
            }
            $name = $owners->get($order->owner_user_id);
            $tickets[] = new TicketView(
                $order->id,
                [
                    'id' => $order->id,
                    'order_no' => $order->order_no,
                    'type' => $order->type->value,
                    'table_code' => $order->table_code,
                    'owner' => ['id' => $order->owner_user_id, 'name' => is_string($name) ? $name : null],
                    'sent_at' => $order->sent_at?->toIso8601String(),
                    'notes' => $order->notes,
                ],
                array_values(array_map(
                    static fn (OrderLine $line): array => (new OrderLineResource($line))->resolve(),
                    ($lines->get($orderId) ?? collect())->all(),
                )),
            );
        }

        return $tickets;
    }

    private static function parse(?string $cursor): ?int
    {
        if ($cursor === null || preg_match('/\A\d{1,18}\z/', $cursor) !== 1) {
            return null;
        }

        return (int) $cursor;
    }
}
