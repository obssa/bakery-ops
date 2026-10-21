<?php

declare(strict_types=1);

use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Events\OrderSent;
use App\Shared\Live\Broadcasts\OrderSentBroadcast;
use App\Shared\Models\Branch;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Tests\Support\OrdersMenu;

/*
 * POST /api/v1/orders/{order}/send (orders.create, own order or stations.all; docs/phases/phase-1-plan.md section 4,
 * section 6 item 3 "1.3b send"; ADR 0008; rule 3). SendOrder is ONE database transaction: it numbers the order from
 * order_counters (the day's row locked), snapshots every line's price with Prices::at(sent_at) and its modifiers' price
 * and recipe deltas, records the active recipe version of to-order lines, routes each line (Routing::stationFor; a
 * display-case line has no station and is ready at send), reserves every line in ONE Reservations::reserveMany call
 * (one reservation ref per line, UUIDv5 of the line's client UUID), writes order_events and, after the commit,
 * dispatches OrderSent and its broadcast companion. When any line is short nothing at all is written: 409
 * orders.send.shortfall names the line, the ingredient, the quantity short in its base unit and the station, in English
 * and Amharic, next to the order (still open). A sent order answers 200 with itself; a cancelled one 409.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->menu = OrdersMenu::build($this->branch);
    $this->waiter = loginAs(makeUser($this->branch, 'waiter', ['name' => 'Abebe']));

    $this->post = fn (string $uri, array $body = []): TestResponse => $this->withHeaders(idempotencyKey())->postJson($uri, $body);
    $this->openOrder = fn (array $lines, array $extra = []): array => ($this->post)('/api/v1/orders', $this->menu->order($lines, $extra + ['send' => false]))
        ->assertCreated()->assertJsonPath('status', 'open')->json();
    $this->send = fn (int $orderId): TestResponse => ($this->post)("/api/v1/orders/{$orderId}/send");
    $this->refOf = fn (string $clientUuid): string => Uuid::uuid5(Uuid::NAMESPACE_URL, "order-line:{$this->branch->id}:{$clientUuid}")->toString();
    // what a reservation ref holds, item id => qty, as written to the ledger
    $this->reserved = fn (string $ref): array => DB::table('stock_movements')->where('reservation_ref', $ref)->where('reason', 'reservation')
        ->orderBy('item_id')->pluck('qty', 'item_id')->map(fn ($q): string => (string) $q)->all();
});

it('sends an open order: numbered, priced at sent_at, routed, one reservation per line in base units, events written', function () {
    $m = $this->menu;
    $sandwich = $m->line($m->sandwich, '2', [$m->extraChicken, $m->noOnion]);
    $latte = $m->line($m->latte, '1', [$m->large]);
    $croissant = $m->line($m->croissant, '2');
    $water = $m->line($m->water, '1');
    $order = ($this->openOrder)([$sandwich, $latte, $croissant, $water], ['type' => 'dine_in']);
    $this->travelTo(CarbonImmutable::now()->addMinutes(3)->startOfSecond());
    $movementsBefore = DB::table('stock_movements')->count();

    $response = ($this->send)($order['id']);

    $sentAt = CarbonImmutable::now();
    $response->assertOk()
        ->assertJsonPath('id', $order['id'])
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('order_no', 1)
        ->assertJsonPath('business_date', $sentAt->setTimezone('Africa/Addis_Ababa')->toDateString())
        ->assertJsonPath('sent_at', $sentAt->toIso8601String())
        ->assertJsonPath('owner.id', $this->waiter->id)
        ->assertJsonPath('owner.name', 'Abebe')
        ->assertJsonPath('totals.subtotal', '910.00') // 2 x (250 + 60) + (120 + 20) + 2 x 60 + 30
        ->assertJsonPath('totals.discount', '0.00')
        ->assertJsonPath('totals.tax', '0.00')
        ->assertJsonPath('totals.total', '910.00')
        ->assertJsonPath('totals.paid', '0.00')
        ->assertJsonPath('totals.balance', '910.00')
        ->assertJsonPath('version', $order['version'] + 1);

    $lines = collect($response->json('lines'))->keyBy('client_uuid');
    expect($lines)->toHaveCount(4);
    expect($lines[$sandwich['client_uuid']])->toMatchArray([
        'qty' => '2.0000', 'unit_price' => '250.00', 'modifier_price' => '60.00', 'line_total' => '620.00',
        'status' => 'queued', 'recipe_version' => 1, 'ready_at' => null,
    ])->and($lines[$sandwich['client_uuid']]['station'])->toMatchArray(['id' => $m->kitchenStation->id, 'code' => 'kitchen', 'name_en' => 'Kitchen', 'name_am' => 'ኩሽና'])
        ->and(collect($lines[$sandwich['client_uuid']]['modifiers'])->pluck('name_en')->all())->toBe(['No onion', 'Extra chicken'])
        ->and($lines[$sandwich['client_uuid']]['item'])->toBe(['id' => $m->sandwich->id, 'name_en' => 'Chicken sandwich', 'name_am' => 'የዶሮ ሳንድዊች']);
    expect($lines[$latte['client_uuid']])->toMatchArray(['unit_price' => '120.00', 'modifier_price' => '20.00', 'line_total' => '140.00', 'status' => 'queued', 'recipe_version' => 1])
        ->and($lines[$latte['client_uuid']]['station']['id'])->toBe($m->barStation->id);
    // display-case lines: no station, no recipe version, ready at send (the cashier picks them)
    expect($lines[$croissant['client_uuid']])->toMatchArray(['line_total' => '120.00', 'status' => 'ready', 'station' => null, 'recipe_version' => null, 'ready_at' => $sentAt->toIso8601String()])
        ->and($lines[$water['client_uuid']])->toMatchArray(['line_total' => '30.00', 'status' => 'ready', 'station' => null]);

    // one reservation per line, keyed by the line's derived ref, at the line's location, in base units (waste and
    // modifier deltas included: 2 x (126 + 50) g chicken, no onion; 220 + 100 ml milk)
    expect(OrderLine::query()->where('order_id', $order['id'])->orderBy('id')->get()->map(fn (OrderLine $l): array => [$l->reservation_ref, $l->location_id])->all())
        ->toBe([
            [($this->refOf)($sandwich['client_uuid']), $m->kitchen->id],
            [($this->refOf)($latte['client_uuid']), $m->bar->id],
            [($this->refOf)($croissant['client_uuid']), $m->display->id],
            [($this->refOf)($water['client_uuid']), $m->display->id],
        ]);
    expect(($this->reserved)(($this->refOf)($sandwich['client_uuid'])))->toEqual([
        $m->chicken->id => '352.0000', $m->lettuce->id => '40.0000', $m->butter->id => '20.0000', $m->bread->id => '4.0000',
    ])
        ->and(($this->reserved)(($this->refOf)($latte['client_uuid'])))->toEqual([$m->espresso->id => '18.0000', $m->milk->id => '320.0000'])
        ->and(($this->reserved)(($this->refOf)($croissant['client_uuid'])))->toEqual([$m->croissant->id => '2.0000'])
        ->and(($this->reserved)(($this->refOf)($water['client_uuid'])))->toEqual([$m->water->id => '1.0000'])
        ->and(DB::table('stock_movements')->count())->toBe($movementsBefore + 8, 'exactly the reservation rows, nothing else')
        ->and(DB::table('stock_movements')->where('id', '>', 0)->orderByDesc('id')->limit(8)->pluck('reason')->unique()->all())->toBe(['reservation']);
    expect(stockRow($m->chicken, $m->kitchen))->on_hand->toBe('1000.0000')->reserved->toBe('352.0000')
        ->and(stockRow($m->onion, $m->kitchen))->reserved->toBe('0.0000')
        ->and(stockRow($m->milk, $m->bar))->reserved->toBe('320.0000')
        ->and(stockRow($m->croissant, $m->display))->on_hand->toBe('5.0000')->reserved->toBe('2.0000');
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);

    // the timeline: order.sent, one line.queued per station line (carrying its station: the KDS cursor), line.ready for
    // each display-case line; every row carries the owner (the pad and till cursor) and who sent it
    $events = OrderEvent::query()->where('order_id', $order['id'])->where('event', '!=', 'order.created')->orderBy('id')->get();
    expect($events->map(fn (OrderEvent $e): array => [$e->event->value, $e->station_id])->all())->toBe([
        ['order.sent', null],
        ['line.queued', $m->kitchenStation->id],
        ['line.queued', $m->barStation->id],
        ['line.ready', null],
        ['line.ready', null],
    ])
        ->and($events->pluck('owner_user_id')->unique()->all())->toBe([$this->waiter->id])
        ->and($events->pluck('user_id')->unique()->all())->toBe([$this->waiter->id])
        ->and($events->first()->payload)->toMatchArray(['order_no' => 1, 'total' => '910.00'])
        ->and(DB::table('audit_log')->where('action', 'order.send')->where('subject_id', $order['id'])->count())->toBe(1);
})->group('module:orders', 'feature');

it('numbers orders per branch and business day in Addis Ababa time, from 1 each day', function () {
    $other = Branch::factory()->create();
    actInBranch($other);
    $otherAdmin = makeUser($other, 'admin');
    withActor($otherAdmin->id);
    $otherMenu = OrdersMenu::build($other);
    $otherWaiter = makeUser($other, 'waiter');
    actInBranch($this->branch);

    $send = function (OrdersMenu $menu): TestResponse {
        $order = ($this->post)('/api/v1/orders', $menu->order([$menu->line($menu->water)], ['send' => false]))->assertCreated()->json();

        return ($this->send)($order['id'])->assertOk();
    };

    // the day after tomorrow, 23:59 in Addis Ababa (20:59 UTC), then 00:01 the next local day (21:01 UTC)
    $day = CarbonImmutable::now('Africa/Addis_Ababa')->addDays(2)->startOfDay();
    $next = $day->addDay();
    $this->travelTo($day->setTime(23, 59)->utc());
    expect($send($this->menu)->json())->toMatchArray(['order_no' => 1, 'business_date' => $day->toDateString()])
        ->and($send($this->menu)->json())->toMatchArray(['order_no' => 2, 'business_date' => $day->toDateString()]);

    loginAs($otherWaiter);
    expect($send($otherMenu)->json())->toMatchArray(['order_no' => 1, 'business_date' => $day->toDateString()], 'another branch counts on its own');

    loginAs($this->waiter);
    $this->travelTo($next->setTime(0, 1)->utc());
    expect($send($this->menu)->json())->toMatchArray(['order_no' => 1, 'business_date' => $next->toDateString()]);

    expect(DB::table('order_counters')->where('branch_id', $this->branch->id)->orderBy('business_date')->get(['business_date', 'last_no'])->map(fn ($r): array => [substr((string) $r->business_date, 0, 10), (int) $r->last_no])->all())
        ->toBe([[$day->toDateString(), 2], [$next->toDateString(), 1]]);
})->group('module:orders', 'feature');

it('snapshots the price in force at sent_at: a change while the cart is open is the new price, a change after the send moves nothing', function () {
    $m = $this->menu;
    $line = $m->line($m->sandwich, '1');
    $order = ($this->openOrder)([$line]);
    expect($order['lines'][0]['unit_price'])->toBe('250.00', 'the cart shows the price now');

    $this->travelTo(CarbonImmutable::now()->addMinute()->startOfSecond());
    withActor($this->admin->id);
    app(PriceHistory::class)->open($m->sandwich, Money::of('270.00'), CarbonImmutable::now());

    ($this->send)($order['id'])->assertOk()->assertJsonPath('lines.0.unit_price', '270.00')->assertJsonPath('totals.total', '270.00');

    $this->travelTo(CarbonImmutable::now()->addMinute()->startOfSecond());
    withActor($this->admin->id);
    app(PriceHistory::class)->open($m->sandwich, Money::of('300.00'), CarbonImmutable::now());

    ($this->send)($order['id'])->assertOk()->assertJsonPath('lines.0.unit_price', '270.00')->assertJsonPath('totals.total', '270.00');
    expect(DB::table('order_lines')->where('order_id', $order['id'])->value('unit_price_snapshot'))->toBe('270.00');
})->group('module:orders', 'feature');

it('reserves what the modifiers make of the recipe: no onion is not refused when the onion is out', function () {
    $m = $this->menu;
    stockIn($m->onion, $m->kitchen, '-200', MovementReason::Waste);

    $plain = ($this->openOrder)([$m->line($m->sandwich)]);
    ($this->send)($plain['id'])->assertStatus(409)
        ->assertJsonPath('message_key', 'orders.send.shortfall')
        ->assertJsonPath('shortfall.0.ingredient_item_id', $m->onion->id)
        ->assertJsonPath('shortfall.0.qty_short', '15.0000');

    $line = $m->line($m->sandwich, '1', [$m->noOnion, $m->withTomato]);
    $order = ($this->openOrder)([$line]);
    ($this->send)($order['id'])->assertOk()->assertJsonPath('status', 'sent')->assertJsonPath('lines.0.line_total', '260.00');
    $held = ($this->reserved)(($this->refOf)($line['client_uuid']));
    expect($held)->not->toHaveKey($m->onion->id)
        ->and($held[$m->tomato->id])->toBe('40.0000', 'the optional tomato is reserved because a modifier adds it')
        ->and($held[$m->chicken->id])->toBe('126.0000');
})->group('module:orders', 'feature');

it('answers a shortfall naming the line, the ingredient, the quantity short with its unit and the station in both languages, and writes nothing', function () {
    $m = $this->menu;
    stockIn($m->chicken, $m->kitchen, '-868', MovementReason::Waste); // 132 g of chicken left
    $sandwich = $m->line($m->sandwich, '2');
    $latte = $m->line($m->latte);
    $order = ($this->openOrder)([$sandwich, $latte]);
    $line = OrderLine::query()->where('client_uuid', $sandwich['client_uuid'])->firstOrFail();
    $before = [
        'movements' => DB::table('stock_movements')->count(),
        'events' => DB::table('order_events')->count(),
        'audit' => DB::table('audit_log')->count(),
        'counters' => DB::table('order_counters')->count(),
        'order' => (array) DB::table('orders')->where('id', $order['id'])->first(),
        'lines' => DB::table('order_lines')->where('order_id', $order['id'])->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all(),
    ];

    $response = ($this->send)($order['id']);

    $response->assertStatus(409)
        ->assertJsonPath('message_key', 'orders.send.shortfall')
        ->assertJsonPath('message_en', 'Some lines cannot be made with the stock on hand. Nothing was sent: change those lines and send again.')
        ->assertJsonPath('message_am', 'አንዳንድ ዝርዝሮች ባለው ክምችት ሊዘጋጁ አይችሉም። ምንም አልተላከም፤ እነዚያን ዝርዝሮች ቀይረው እንደገና ይላኩ።')
        ->assertJsonPath('order.id', $order['id'])
        ->assertJsonPath('order.status', 'open')
        ->assertJsonPath('order.order_no', null);
    expect($response->json('shortfall'))->toBe([[
        'line_client_uuid' => $sandwich['client_uuid'],
        'line_id' => $line->id,
        'item_id' => $m->sandwich->id,
        'item_name_en' => 'Chicken sandwich',
        'item_name_am' => 'የዶሮ ሳንድዊች',
        'qty' => '2.0000',
        'ingredient_item_id' => $m->chicken->id,
        'ingredient_name_en' => 'Chicken breast',
        'ingredient_name_am' => 'የዶሮ ፍርምባ',
        'needed' => '252.0000',
        'available' => '132.0000',
        'qty_short' => '120.0000',
        'unit' => 'g',
        'qty_short_display' => '120 g',
        'location_id' => $m->kitchen->id,
        'station_id' => $m->kitchenStation->id,
        'station_name_en' => 'Kitchen',
        'station_name_am' => 'ኩሽና',
        'message_en' => 'Cannot add Chicken sandwich ×2: Chicken breast short by 120 g (Kitchen).',
        'message_am' => 'የዶሮ ሳንድዊች ×2 ማከል አይቻልም፤ የዶሮ ፍርምባ 120 g ጎድሏል (ኩሽና)።',
    ]]);

    // nothing at all: no reservation (not even the latte's, which fits), no number, no event, no audit row
    expect(DB::table('stock_movements')->count())->toBe($before['movements'])
        ->and(DB::table('order_events')->count())->toBe($before['events'])
        ->and(DB::table('audit_log')->count())->toBe($before['audit'])
        ->and(DB::table('order_counters')->count())->toBe($before['counters'])
        ->and((array) DB::table('orders')->where('id', $order['id'])->first())->toBe($before['order'])
        ->and(DB::table('order_lines')->where('order_id', $order['id'])->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all())->toBe($before['lines'])
        ->and(stockRow($m->chicken, $m->kitchen))->reserved->toBe('0.0000')
        ->and(stockRow($m->milk, $m->bar))->reserved->toBe('0.0000');
})->group('module:orders', 'feature');

it('sends after the short line is fixed, and the order takes the first number of the day', function () {
    $m = $this->menu;
    stockIn($m->chicken, $m->kitchen, '-868', MovementReason::Waste);
    $sandwich = $m->line($m->sandwich, '2');
    $order = ($this->openOrder)([$sandwich]);
    ($this->send)($order['id'])->assertStatus(409);

    $this->withHeaders(idempotencyKey())->putJson("/api/v1/orders/{$order['id']}/lines", ['lines' => [['client_uuid' => $sandwich['client_uuid'], 'item_id' => $m->sandwich->id, 'qty' => '1']]])
        ->assertOk();
    ($this->send)($order['id'])->assertOk()
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('order_no', 1)
        ->assertJsonPath('totals.total', '250.00');
    expect(stockRow($m->chicken, $m->kitchen))->reserved->toBe('126.0000');
})->group('module:orders', 'feature');

it('covers the lines in the order they were sent: the line that does not fit in what the earlier ones left is the short one', function () {
    $m = $this->menu;
    stockIn($m->chicken, $m->kitchen, '-868', MovementReason::Waste); // 132 g: one sandwich fits, two do not
    $first = $m->line($m->sandwich);
    $second = $m->line($m->sandwich);
    $order = ($this->openOrder)([$first, $second]);

    $response = ($this->send)($order['id'])->assertStatus(409);

    expect(collect($response->json('shortfall'))->pluck('line_client_uuid')->all())->toBe([$second['client_uuid']])
        ->and($response->json('shortfall.0'))->toMatchArray(['needed' => '126.0000', 'available' => '6.0000', 'qty_short' => '120.0000'])
        ->and($response->json('shortfall.0.message_en'))->toBe('Cannot add Chicken sandwich ×1: Chicken breast short by 120 g (Kitchen).');
})->group('module:orders', 'feature');

it('sells the last cake once: the second order gets the shortfall at the display case', function () {
    $m = $this->menu;
    $first = ($this->openOrder)([$m->line($m->cake)]);
    $second = ($this->openOrder)([$m->line($m->cake)]);

    ($this->send)($first['id'])->assertOk()->assertJsonPath('status', 'ready');
    ($this->send)($second['id'])->assertStatus(409)
        ->assertJsonPath('order.status', 'open')
        ->assertJsonPath('shortfall.0.ingredient_item_id', $m->cake->id)
        ->assertJsonPath('shortfall.0.qty_short_display', '1 pcs')
        ->assertJsonPath('shortfall.0.station_id', $m->tillStation->id)
        ->assertJsonPath('shortfall.0.message_en', 'Cannot add Black forest cake ×1: Black forest cake short by 1 pcs (Till).');

    expect(stockRow($m->cake, $m->display))->on_hand->toBe('1.0000')->reserved->toBe('1.0000');
})->group('module:orders', 'feature');

it('makes an order of display-case lines ready at send', function () {
    $m = $this->menu;
    $order = ($this->openOrder)([$m->line($m->croissant), $m->line($m->water, '2')]);

    ($this->send)($order['id'])->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('lines.0.status', 'ready')
        ->assertJsonPath('lines.1.status', 'ready');

    expect(OrderEvent::query()->where('order_id', $order['id'])->orderBy('id')->pluck('event')->map->value->all())
        ->toBe(['order.created', 'order.sent', 'line.ready', 'line.ready', 'order.ready']);
})->group('module:orders', 'feature');

it('answers a sent order with itself and writes nothing more', function () {
    $m = $this->menu;
    $order = ($this->openOrder)([$m->line($m->sandwich)]);
    $sent = ($this->send)($order['id'])->assertOk()->json();
    $counts = fn (): array => [DB::table('stock_movements')->count(), DB::table('order_events')->count(), DB::table('audit_log')->count(), DB::table('order_counters')->value('last_no')];
    $before = $counts();

    $again = ($this->send)($order['id'])->assertOk();

    expect($again->json())->toBe($sent)
        ->and($counts())->toBe($before);
})->group('module:orders', 'feature');

it('refuses to send a cancelled order', function () {
    $order = ($this->openOrder)([$this->menu->line($this->menu->water)]);
    ($this->post)("/api/v1/orders/{$order['id']}/cancel")->assertOk();

    ($this->send)($order['id'])->assertStatus(409)
        ->assertJsonPath('message_key', 'orders.order.not_open')
        ->assertJsonPath('status', 'cancelled');
    expect(DB::table('stock_movements')->where('reason', 'reservation')->count())->toBe(0);
})->group('module:orders', 'feature');

it('refuses a line that can no longer be sold when it is sent, naming it, and writes nothing', function () {
    $m = $this->menu;
    $water = $m->line($m->water);
    $order = ($this->openOrder)([$m->line($m->croissant), $water]);
    $m->water->forceFill(['is_active' => false])->save();

    ($this->send)($order['id'])->assertStatus(422)
        ->assertJsonPath('message_key', 'orders.item.not_sellable')
        ->assertJsonPath('line_client_uuid', $water['client_uuid'])
        ->assertJsonPath('item_id', $m->water->id);
    expect(DB::table('stock_movements')->where('reason', 'reservation')->count())->toBe(0)
        ->and(Order::query()->findOrFail($order['id'])->status->value)->toBe('open');
})->group('module:orders', 'feature');

it('lets the owner and the stations.all roles send, refuses another waiter and the roles without orders.create', function () {
    $m = $this->menu;
    $mine = ($this->openOrder)([$m->line($m->water)]);

    loginAs(makeUser($this->branch, 'waiter'));
    ($this->send)($mine['id'])->assertStatus(403)->assertJsonPath('message_key', 'orders.order.not_owner');
    loginAs(makeUser($this->branch, 'cashier'));
    ($this->send)($mine['id'])->assertStatus(403)->assertJsonPath('message_key', 'orders.order.not_owner');
    foreach (['chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        ($this->send)($mine['id'])->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect(Order::query()->findOrFail($mine['id'])->status->value)->toBe('open');

    loginAs(makeUser($this->branch, 'manager'));
    ($this->send)($mine['id'])->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('owner.id', $this->waiter->id);

    loginAs($this->waiter);
    $another = ($this->openOrder)([$m->line($m->water)]);
    loginAs(makeUser($this->branch, 'admin'));
    ($this->send)($another['id'])->assertOk();
})->group('module:orders', 'feature');

it('answers 404 for an order of another branch', function () {
    $other = Branch::factory()->create();
    $theirs = Order::factory()->create(['branch_id' => $other->id, 'created_by_user_id' => makeUser($other, 'waiter')->id]);

    ($this->send)($theirs->id)->assertStatus(404)->assertJsonPath('message_key', 'http.not_found');
    expect(Order::query()->withoutGlobalScopes()->findOrFail($theirs->id)->status->value)->toBe('open');
})->group('module:orders', 'feature');

it('replays the same Idempotency-Key with the same body and sends once', function () {
    $m = $this->menu;
    $order = ($this->openOrder)([$m->line($m->sandwich), $m->line($m->croissant)]);
    $key = idempotencyKey();

    $first = $this->withHeaders($key)->postJson("/api/v1/orders/{$order['id']}/send")->assertOk();
    $counts = [DB::table('stock_movements')->count(), DB::table('order_events')->count(), DB::table('audit_log')->count()];
    $second = $this->withHeaders($key)->postJson("/api/v1/orders/{$order['id']}/send");

    $second->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and([DB::table('stock_movements')->count(), DB::table('order_events')->count(), DB::table('audit_log')->count()])->toBe($counts);
})->group('module:orders', 'feature');

it('replays a refused send with the same key as the same 409, and a new key sends once the stock is there', function () {
    $m = $this->menu;
    $first = ($this->openOrder)([$m->line($m->cake)]);
    $second = ($this->openOrder)([$m->line($m->cake)]);
    ($this->send)($first['id'])->assertOk();
    $key = idempotencyKey();

    $refused = $this->withHeaders($key)->postJson("/api/v1/orders/{$second['id']}/send")->assertStatus(409);
    withActor($this->admin->id);
    stockIn($m->cake, $m->display, '4');
    $replayed = $this->withHeaders($key)->postJson("/api/v1/orders/{$second['id']}/send");

    $replayed->assertStatus(409)->assertHeader('Idempotency-Replayed', 'true');
    expect($replayed->json())->toBe($refused->json());
    ($this->send)($second['id'])->assertOk()->assertJsonPath('order_no', 2);
})->group('module:orders', 'feature');

it('sends in one database transaction: one BEGIN, every write of the send inside it', function () {
    $m = $this->menu;
    $order = ($this->openOrder)([$m->line($m->sandwich), $m->line($m->latte), $m->line($m->croissant)]);
    // RefreshDatabase wraps the test in a transaction: what production opens with BEGIN starts here one level above it
    // (the idempotency middleware's key row gets a savepoint of its own under that wrapper, outside the send)
    $baseline = DB::connection()->transactionLevel();
    $transaction = 0;
    $writes = [];
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $e) use (&$transaction, $baseline): void {
        if ($e->connection->transactionLevel() === $baseline + 1) {
            $transaction++;
        }
    });
    DB::listen(function (QueryExecuted $q) use (&$writes, &$transaction, $baseline): void {
        foreach (['/^\s*insert\s+(?:ignore\s+)?into\s+[`"]?(\w+)/i', '/^\s*update\s+[`"]?(\w+)/i', '/^\s*delete\s+from\s+[`"]?(\w+)/i'] as $pattern) {
            if (preg_match($pattern, $q->sql, $match) === 1) {
                $writes[] = [$match[1], $q->connection->transactionLevel() > $baseline ? $transaction : null];

                return;
            }
        }
    });

    ($this->send)($order['id'])->assertOk();

    $sendWrites = collect($writes)->reject(fn (array $w): bool => $w[0] === 'idempotency_keys');
    expect($sendWrites->pluck(0)->unique()->sort()->values()->all())
        ->toBe(['audit_log', 'order_counters', 'order_events', 'order_lines', 'orders', 'stock_balances', 'stock_movements'])
        ->and($sendWrites->pluck(1)->contains(null))->toBeFalse('no write of the send runs outside a transaction')
        ->and($sendWrites->pluck(1)->unique()->values()->all())->toHaveCount(1, 'one BEGIN: every write of the send, savepoints included, is in the same transaction');
})->group('module:orders', 'feature');

it('rolls the whole send back when any of its writes fails: no number, no reservation, no event, no audit row', function () {
    $m = $this->menu;
    $order = ($this->openOrder)([$m->line($m->sandwich), $m->line($m->croissant)]);
    $before = [DB::table('stock_movements')->count(), DB::table('order_events')->count(), DB::table('audit_log')->count(), DB::table('order_counters')->count()];
    // the timeline is written after the reservation and the number: make its first row fail
    Event::listen('eloquent.creating: '.OrderEvent::class, function (): never {
        throw new RuntimeException('the disk is full');
    });

    ($this->send)($order['id'])->assertStatus(500);

    $after = Order::query()->findOrFail($order['id']);
    expect([DB::table('stock_movements')->count(), DB::table('order_events')->count(), DB::table('audit_log')->count(), DB::table('order_counters')->count()])->toBe($before)
        ->and($after->status->value)->toBe('open')
        ->and($after->order_no)->toBeNull()
        ->and($after->sent_at)->toBeNull()
        ->and(stockRow($m->chicken, $m->kitchen))->reserved->toBe('0.0000');
})->group('module:orders', 'feature');

it('dispatches OrderSent once, after the commit, with the order, its stations and its lines', function () {
    $m = $this->menu;
    $order = ($this->openOrder)([$m->line($m->sandwich), $m->line($m->latte), $m->line($m->croissant)]);
    Event::fake([OrderSent::class]);

    ($this->send)($order['id'])->assertOk();

    $lineIds = OrderLine::query()->where('order_id', $order['id'])->orderBy('id')->pluck('id')->all();
    Event::assertDispatchedTimes(OrderSent::class, 1);
    Event::assertDispatched(OrderSent::class, function (OrderSent $e) use ($order, $lineIds, $m): bool {
        return $e->branchId === $this->branch->id
            && $e->payload() === [
                'order_id' => $order['id'],
                'order_no' => 1,
                'owner_user_id' => $this->waiter->id,
                'station_ids' => [$m->kitchenStation->id, $m->barStation->id],
                'line_ids' => $lineIds,
            ];
    });
})->group('module:orders', 'feature');

it('sends the OrderSent companion to each station of the order, and queues nothing under the null transport', function () {
    $m = $this->menu;
    $order = ($this->openOrder)([$m->line($m->sandwich), $m->line($m->latte), $m->line($m->croissant)]);
    $companions = [];
    Event::listen(OrderSentBroadcast::class, function (OrderSentBroadcast $b) use (&$companions): void {
        $companions[] = $b;
    });
    Queue::fake();

    ($this->send)($order['id'])->assertOk();

    expect($companions)->toHaveCount(1)
        ->and(array_map(fn ($c): string => $c->name, $companions[0]->broadcastOn()))->toBe(["private-station.{$m->kitchenStation->id}", "private-station.{$m->barStation->id}"])
        ->and($companions[0]->broadcastAs())->toBe('order_sent')
        ->and($companions[0]->broadcastWith()['payload']['order_id'])->toBe($order['id'])
        ->and($companions[0]->broadcastWhen())->toBeFalse();
    assertNothingQueuedButStockEvaluation();

    // an order of display-case lines reaches no station: no companion at all
    $counter = ($this->openOrder)([$m->line($m->croissant)]);
    ($this->send)($counter['id'])->assertOk();
    expect($companions)->toHaveCount(1);
})->group('module:orders', 'feature');

it('does not dispatch OrderSent when the send is refused', function () {
    $m = $this->menu;
    stockIn($m->chicken, $m->kitchen, '-1000', MovementReason::Waste);
    $order = ($this->openOrder)([$m->line($m->sandwich)]);
    Event::fake([OrderSent::class]);

    ($this->send)($order['id'])->assertStatus(409);

    Event::assertNotDispatched(OrderSent::class);
})->group('module:orders', 'feature');
