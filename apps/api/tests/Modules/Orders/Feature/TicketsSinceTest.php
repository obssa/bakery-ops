<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Orders\Contracts\Tickets;
use App\Modules\Orders\Contracts\TicketsPage;
use App\Modules\Orders\Contracts\TicketView;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Live\ConditionalPoll;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Carbon\CarbonImmutable;
use Tests\Support\OrderFloor;

/*
 * The provided capability Tickets::since(stationId, cursor) (manifest orders.provides; docs/phases/phase-1-plan.md
 * section 4 "GET /stations/{station}/tickets", ADR 0009; the HTTP endpoint is step 1.5). A station's rail: the
 * orders that have lines at that station, each with those lines, newest changes since the cursor, which is the id of an
 * order_events row held back by the overlap window (Shared Live OverlapCursor) so a late commit is never skipped. A
 * first load (no cursor) returns the orders with a line still on the rail (queued, accepted, ready); a poll returns
 * every order with an event at the station after the cursor, so a line that was served, rejected or voided comes back
 * once with its new status and leaves the rail. The fingerprint is what the ETag is computed from.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->tickets = fn (): Tickets => app(Tickets::class);
    $this->since = fn (?Station $station, ?string $cursor = null): TicketsPage => ($this->tickets)()->since(($station ?? $this->floor->menu->kitchenStation)->id, $cursor);
    $this->settle = fn () => $this->travelTo(CarbonImmutable::now()->addSeconds(10));
});

/** @return list<int> the order ids of a page, in order */
function ticketOrderIds(TicketsPage $page): array
{
    return array_map(static fn (TicketView $t): int => $t->orderId, $page->data);
}

it('returns the first page: the orders with a line on this station, the order header and only this station\'s lines', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->waiter->forceFill(['name' => 'Abebe'])->save();
    $mixed = $f->send([$m->line($m->sandwich, '2', [$m->noOnion], 'no pepper'), $m->line($m->latte), $m->line($m->croissant)], null, ['notes' => 'Window seat']);
    $barOnly = $f->send([$m->line($m->latte)]);
    $displayOnly = $f->send([$m->line($m->croissant)]);
    ($this->settle)();

    $kitchen = ($this->since)($m->kitchenStation);

    expect($kitchen)->toBeInstanceOf(TicketsPage::class)->and(ticketOrderIds($kitchen))->toBe([$mixed['id']]);
    $ticket = $kitchen->data[0];
    expect($ticket->order)->toBe([
        'id' => $mixed['id'], 'order_no' => $mixed['order_no'], 'type' => 'takeaway', 'table_code' => null,
        'owner' => ['id' => $f->waiter->id, 'name' => 'Abebe'], 'sent_at' => $mixed['sent_at'], 'notes' => 'Window seat',
    ])->and($ticket->lines)->toHaveCount(1)
        ->and($ticket->lines[0])->toMatchArray([
            'id' => $f->lineOf($mixed, $m->sandwich)['id'], 'qty' => '2.0000', 'status' => 'queued', 'notes' => 'no pepper',
            'item' => ['id' => $m->sandwich->id, 'name_en' => 'Chicken sandwich', 'name_am' => 'የዶሮ ሳንድዊች'],
        ])
        ->and($ticket->lines[0]['modifiers'][0]['name_en'])->toBe('No onion')
        ->and($ticket->lines[0]['station']['code'])->toBe('kitchen');

    expect(ticketOrderIds(($this->since)($m->barStation)))->toBe([$mixed['id'], $barOnly['id']])
        ->and(ticketOrderIds(($this->since)($m->tillStation)))->toBe([])
        ->and($displayOnly['id'])->not->toBeIn(ticketOrderIds($kitchen));

    $body = $kitchen->toArray();
    expect(array_keys($body))->toBe(['data', 'cursor', 'server_time'])
        ->and($body['data'][0]['order']['id'])->toBe($mixed['id'])->and($body['data'][0]['lines'][0]['id'])->toBe($ticket->lines[0]['id'])
        ->and($body['cursor'])->toBeString()->and($body['server_time'])->toBe($kitchen->serverTime->toIso8601String());
})->group('module:orders', 'feature');

it('orders the tickets oldest first, by the order of their first event at the station', function () {
    $f = $this->floor;
    $m = $f->menu;
    $a = $f->send([$m->line($m->sandwich)]);
    $b = $f->send([$m->line($m->sandwich)]);
    $c = $f->send([$m->line($m->sandwich)]);
    ($this->settle)();

    expect(ticketOrderIds(($this->since)($m->kitchenStation)))->toBe([$a['id'], $b['id'], $c['id']]);
})->group('module:orders', 'feature');

it('hands back a monotonic cursor that never goes below the one sent and only moves past events older than the window', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->send([$m->line($m->sandwich)]);

    $fresh = ($this->since)($m->kitchenStation);
    expect($fresh->cursor)->toBe('0')->and($fresh->data)->toHaveCount(1);

    ($this->settle)();
    $first = ($this->since)($m->kitchenStation);
    $highest = (int) OrderEvent::query()->where('station_id', $m->kitchenStation->id)->max('id');
    expect($first->cursor)->toBe((string) $highest);

    $quiet = ($this->since)($m->kitchenStation, $first->cursor);
    expect($quiet->data)->toBe([])->and($quiet->cursor)->toBe($first->cursor);

    $second = $f->send([$m->line($m->sandwich)]);
    $next = ($this->since)($m->kitchenStation, $first->cursor);
    expect(ticketOrderIds($next))->toBe([$second['id']])->and($next->cursor)->toBe($first->cursor);

    ($this->settle)();
    $later = ($this->since)($m->kitchenStation, $first->cursor);
    expect(ticketOrderIds($later))->toBe([$second['id']])
        ->and((int) $later->cursor)->toBeGreaterThan((int) $first->cursor)
        ->and(($this->since)($m->kitchenStation, $later->cursor)->data)->toBe([]);

    $ahead = ($this->since)($m->kitchenStation, '999999');
    expect($ahead->cursor)->toBe('999999')->and($ahead->data)->toBe([]);
    expect(($this->since)($m->kitchenStation, 'not-a-cursor')->cursor)->toBe($later->cursor);
})->group('module:orders', 'feature');

it('still delivers an event that commits late, below an id already seen, while it is inside the overlap window', function () {
    $f = $this->floor;
    $m = $f->menu;
    $kitchen = $m->kitchenStation;
    $f->send([$m->line($m->sandwich)]);
    ($this->settle)();
    $cursor = ($this->since)($kitchen)->cursor;
    $top = (int) OrderEvent::query()->max('id');

    // two other tickets: the event with the higher id commits first, the lower one a moment later
    $early = Order::factory()->sent(41)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->waiter->id]);
    $late = Order::factory()->sent(42)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->waiter->id]);
    $line = fn (Order $o): OrderLine => OrderLine::factory()->atStation($kitchen)->create(['order_id' => $o->id]);
    $earlyLine = $line($early);
    $lateLine = $line($late);
    $event = fn (Order $o, OrderLine $l, int $id) => OrderEvent::factory()->create([
        'id' => $id, 'order_id' => $o->id, 'order_line_id' => $l->id, 'station_id' => $kitchen->id, 'event' => OrderEventType::LineQueued, 'created_at' => CarbonImmutable::now(),
    ]);

    $event($early, $earlyLine, $top + 10);
    $seen = ($this->since)($kitchen, $cursor);
    expect(ticketOrderIds($seen))->toBe([$early->id])->and($seen->cursor)->toBe($cursor);

    $event($late, $lateLine, $top + 5); // committed after id top+10 was already served
    $again = ($this->since)($kitchen, $seen->cursor);
    expect(ticketOrderIds($again))->toBe([$late->id, $early->id])->and($again->cursor)->toBe($cursor);

    ($this->settle)();
    $settled = ($this->since)($kitchen, $again->cursor);
    expect($settled->cursor)->toBe((string) ($top + 10))->and(ticketOrderIds($settled))->toBe([$late->id, $early->id]);
    // with the cursor past both, neither comes again
    expect(($this->since)($kitchen, $settled->cursor)->data)->toBe([]);
})->group('module:orders', 'feature');

it('lets a served, rejected or voided line leave the rail: it comes back once with its new status and not on a first load', function () {
    $f = $this->floor;
    $m = $f->menu;
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    $served = $f->send([$m->line($m->sandwich)]);
    $rejected = $f->send([$m->line($m->sandwich)]);
    $voided = $f->send([$m->line($m->sandwich)]);
    $stays = $f->send([$m->line($m->sandwich)]);
    ($this->settle)();
    $cursor = ($this->since)($m->kitchenStation)->cursor;
    expect(ticketOrderIds(($this->since)($m->kitchenStation)))->toBe([$served['id'], $rejected['id'], $voided['id'], $stays['id']]);

    $f->act('ready', $f->lineOf($served, $m->sandwich)['id'], $f->chef)->assertOk();
    $f->act('served', $f->lineOf($served, $m->sandwich)['id'], $f->waiter)->assertOk();
    $f->act('reject', $f->lineOf($rejected, $m->sandwich)['id'], $f->chef, ['reason' => 'equipment_down'])->assertOk();
    loginAs($f->cashier);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$voided['id']}/void", ['reason' => 'Left', 'pin' => '246802'])->assertOk();
    $f->act('accept', $f->lineOf($stays, $m->sandwich)['id'], $f->chef)->assertOk();

    $poll = ($this->since)($m->kitchenStation, $cursor);
    $statuses = [];
    foreach ($poll->data as $ticket) {
        $statuses[$ticket->orderId] = array_column($ticket->lines, 'status');
    }
    expect($statuses)->toBe([
        $served['id'] => ['served'], $rejected['id'] => ['rejected'], $voided['id'] => ['voided'], $stays['id'] => ['accepted'],
    ]);

    ($this->settle)();
    expect(ticketOrderIds(($this->since)($m->kitchenStation)))->toBe([$stays['id']]);
})->group('module:orders', 'feature');

it('keeps every line of the station in a ticket whose other lines are done, and a ticket reappears when a sibling line changes', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich), $m->line($m->sandwich, '2')]);
    [$one, $two] = array_column($order['lines'], 'id');
    $f->act('ready', $one, $f->chef)->assertOk();
    $f->act('served', $one, $f->waiter)->assertOk();
    ($this->settle)();

    $page = ($this->since)($m->kitchenStation);
    expect(ticketOrderIds($page))->toBe([$order['id']])
        ->and(array_column($page->data[0]->lines, 'status', 'id'))->toBe([$one => 'served', $two => 'queued']);
})->group('module:orders', 'feature');

it('does not show another branch\'s station, nor events of another station', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->send([$m->line($m->sandwich)]);
    ($this->settle)();
    $other = Branch::factory()->create();
    $theirStation = Station::factory()->create(['branch_id' => $other->id]);
    $theirOrder = Order::factory()->sent()->create(['branch_id' => $other->id]);
    $theirLine = OrderLine::factory()->atStation($theirStation)->create(['order_id' => $theirOrder->id]);
    OrderEvent::factory()->create(['order_id' => $theirOrder->id, 'order_line_id' => $theirLine->id, 'station_id' => $theirStation->id, 'event' => OrderEventType::LineQueued]);
    ($this->settle)();

    $page = ($this->since)($theirStation);
    expect($page->data)->toBe([])->and($page->cursor)->toBe('0');
    expect(($this->tickets)()->fingerprint($theirStation->id, null)['max'])->toBeNull();
})->group('module:orders', 'feature');

it('has a fingerprint that stays while nothing changes and changes when the tail does', function () {
    $f = $this->floor;
    $m = $f->menu;
    $kitchen = $m->kitchenStation;
    $fp = fn (?string $cursor = null) => ($this->tickets)()->fingerprint($kitchen->id, $cursor);
    $f->send([$m->line($m->sandwich)]);
    ($this->settle)();

    $before = $fp();
    expect($fp())->toBe($before)
        ->and(($this->since)($kitchen)->fingerprint)->toBe($before)
        ->and($before)->toHaveKeys(['since', 'next', 'max', 'pending']);

    // a new event changes it
    $order = $f->send([$m->line($m->sandwich)]);
    $withNew = $fp();
    expect($withNew)->not->toBe($before);

    // a late commit below the highest id changes it although the highest id does not move
    $top = (int) OrderEvent::query()->max('id');
    $other = Order::factory()->sent(77)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->waiter->id]);
    $otherLine = OrderLine::factory()->atStation($kitchen)->create(['order_id' => $other->id]);
    $queued = fn (int $id) => OrderEvent::factory()->create([
        'id' => $id, 'order_id' => $other->id, 'order_line_id' => $otherLine->id, 'station_id' => $kitchen->id, 'event' => OrderEventType::LineQueued,
    ]);
    $queued($top + 100);
    $afterHigh = $fp();
    $queued($top + 50);
    $afterLate = $fp();
    expect($afterLate['max'])->toBe($afterHigh['max'])->and($afterLate)->not->toBe($afterHigh);

    // the cursor the client sent is part of it
    expect($fp((string) $top))->not->toBe($afterLate)->and($fp((string) $top)['since'])->toBe($top);

    // events at another station do not touch this station's fingerprint
    $f->send([$m->line($m->latte)]);
    expect($fp())->toBe($afterLate);
})->group('module:orders', 'feature');

it('builds the ETag from the station and the fingerprint', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->send([$m->line($m->sandwich)]);
    ($this->settle)();

    $page = ($this->since)($m->kitchenStation);
    $etag = $page->etag($m->kitchenStation->id);

    expect($etag)->toBe(ConditionalPoll::etag('tickets', ['station' => $m->kitchenStation->id] + $page->fingerprint))
        ->and(($this->since)($m->kitchenStation)->etag($m->kitchenStation->id))->toBe($etag)
        ->and(($this->since)($m->barStation)->etag($m->barStation->id))->not->toBe($etag);

    $f->send([$m->line($m->sandwich)]);
    expect(($this->since)($m->kitchenStation)->etag($m->kitchenStation->id))->not->toBe($etag);
})->group('module:orders', 'feature');
