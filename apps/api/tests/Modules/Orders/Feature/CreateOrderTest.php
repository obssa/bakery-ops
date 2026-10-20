<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Services\ItemStations;
use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Tests\Support\OrdersMenu;

/*
 * POST /api/v1/orders (orders.create: admin, manager, cashier, waiter; docs/phases/phase-1-plan.md section 4). The pad
 * or the till sends the cart with a client UUID for the order and for every line (ADR 0003) and, by default, sends it
 * at once (send: false keeps it an open cart). The cart is written in its own transaction (order.created), then the
 * send runs as SendOrder (one transaction), so a refused send leaves the cart open next to the 409 shortfall. Every
 * line is checked when it is written: a live product of the branch, priced now, a to-order product with an active
 * recipe and a station, a stock location, modifiers of that product; each refusal names the line, bilingually. The
 * waiter acting by short code on a shared pad owns the order; the pad account is its creator (rule 4).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->menu = OrdersMenu::build($this->branch);
    $this->waiter = loginAs(makeUser($this->branch, 'waiter', ['name' => 'Abebe']));
    $this->create = fn (array $body, array $headers = []): TestResponse => $this->withHeaders(idempotencyKey() + $headers)->postJson('/api/v1/orders', $body);
});

it('creates and sends an order in one call: 201, numbered, priced, routed, reserved, timeline and audit written', function () {
    $m = $this->menu;
    $body = $m->order([$m->line($m->sandwich, '2', [], 'no mayo'), $m->line($m->latte), $m->line($m->croissant)], ['notes' => 'birthday']);

    $response = ($this->create)($body);

    $response->assertCreated()
        ->assertJsonPath('client_uuid', $body['client_uuid'])
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('order_no', 1)
        ->assertJsonPath('type', 'takeaway')
        ->assertJsonPath('created_via', 'waiter_pad')
        ->assertJsonPath('owner.id', $this->waiter->id)
        ->assertJsonPath('created_by_user_id', $this->waiter->id)
        ->assertJsonPath('acting_user_id', null)
        ->assertJsonPath('notes', 'birthday')
        ->assertJsonPath('dining_table', null)
        ->assertJsonPath('totals.total', '680.00')
        ->assertJsonPath('currency', 'ETB')
        ->assertJsonPath('lines.0.notes', 'no mayo')
        ->assertJsonPath('lines.0.status', 'queued')
        ->assertJsonPath('lines.2.status', 'ready');

    $order = Order::query()->where('client_uuid', $body['client_uuid'])->firstOrFail();
    expect(Order::query()->count())->toBe(1)
        ->and(OrderEvent::query()->where('order_id', $order->id)->orderBy('id')->pluck('event')->map->value->all())
        ->toBe(['order.created', 'order.sent', 'line.queued', 'line.queued', 'line.ready'])
        ->and(DB::table('audit_log')->where('subject_id', $order->id)->orderBy('id')->pluck('action')->all())->toBe(['order.create', 'order.send'])
        ->and(DB::table('stock_movements')->where('reason', 'reservation')->count())->toBe(8);
})->group('module:orders', 'feature');

it('keeps the cart open with send false: priced now and routed, nothing reserved, no number yet', function () {
    $m = $this->menu;
    $sandwich = $m->line($m->sandwich, '1', [$m->extraChicken]);
    $croissant = $m->line($m->croissant, '3');

    $response = ($this->create)($m->order([$sandwich, $croissant], ['send' => false]));

    $response->assertCreated()
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('order_no', null)
        ->assertJsonPath('business_date', null)
        ->assertJsonPath('sent_at', null)
        ->assertJsonPath('totals.subtotal', '490.00') // (250 + 60) + 3 x 60
        ->assertJsonPath('lines.0.unit_price', '250.00')
        ->assertJsonPath('lines.0.modifier_price', '60.00')
        ->assertJsonPath('lines.0.modifiers.0.name_am', 'ተጨማሪ ዶሮ')
        ->assertJsonPath('lines.0.modifiers.0.price_delta', '60.00')
        ->assertJsonPath('lines.0.station.id', $m->kitchenStation->id)
        ->assertJsonPath('lines.0.status', 'queued')
        ->assertJsonPath('lines.0.recipe_version', null)
        ->assertJsonPath('lines.1.station', null)
        ->assertJsonPath('lines.1.status', 'queued')
        ->assertJsonPath('lines.1.line_total', '180.00');

    $line = OrderLine::query()->where('client_uuid', $sandwich['client_uuid'])->firstOrFail();
    expect($line->reservation_ref)->toBe(Uuid::uuid5(Uuid::NAMESPACE_URL, "order-line:{$this->branch->id}:{$sandwich['client_uuid']}")->toString())
        ->and($line->location_id)->toBe($m->kitchen->id)
        ->and($line->created_by_user_id)->toBe($this->waiter->id)
        ->and($line->modifiers)->toHaveCount(1)
        ->and($line->modifiers->first()->recipe_delta_snapshot)->toBe([['ingredient_item_id' => $m->chicken->id, 'qty' => '50.0000', 'unit' => 'g']])
        ->and(OrderLine::query()->where('client_uuid', $croissant['client_uuid'])->value('location_id'))->toBe($m->display->id)
        ->and(DB::table('stock_movements')->where('reason', 'reservation')->count())->toBe(0)
        ->and(DB::table('order_counters')->count())->toBe(0);
})->group('module:orders', 'feature');

it('refuses a malformed body with validation.failed', function (string $field, array $patch) {
    $m = $this->menu;
    $body = $m->order([$m->line($m->water), $m->line($m->croissant)]);
    foreach ($patch as $path => $value) {
        data_set($body, $path, $value);
    }

    ($this->create)($body)->assertStatus(422)
        ->assertJsonPath('message_key', 'validation.failed')
        ->assertJsonValidationErrors([$field]);
    expect(Order::query()->count())->toBe(0);
})->with([
    'no lines' => ['lines', ['lines' => []]],
    'lines missing' => ['lines', ['lines' => null]],
    'no order client_uuid' => ['client_uuid', ['client_uuid' => null]],
    'an order client_uuid that is not a UUID' => ['client_uuid', ['client_uuid' => 'order-1']],
    'a type the screens do not offer' => ['type', ['type' => 'delivery']],
    'a line without client_uuid' => ['lines.0.client_uuid', ['lines.0.client_uuid' => null]],
    'a line client_uuid that is not a UUID' => ['lines.0.client_uuid', ['lines.0.client_uuid' => 'line-1']],
    'a line without item' => ['lines.0.item_id', ['lines.0.item_id' => null]],
    'qty zero' => ['lines.0.qty', ['lines.0.qty' => '0']],
    'qty negative' => ['lines.0.qty', ['lines.0.qty' => '-1']],
    'qty with five decimals' => ['lines.0.qty', ['lines.0.qty' => '1.23456']],
    'qty as a float' => ['lines.0.qty', ['lines.0.qty' => 1.5]],
    'modifier_ids not a list' => ['lines.0.modifier_ids', ['lines.0.modifier_ids' => 'large']],
    'line notes too long' => ['lines.0.notes', ['lines.0.notes' => str_repeat('x', 501)]],
    'a table on a takeaway' => ['dining_table_id', ['dining_table_id' => 1]],
    'send that is not a boolean' => ['send', ['send' => 'yes']],
])->group('module:orders', 'feature');

it('refuses two lines with one client_uuid, and a line client_uuid another order already used', function () {
    $m = $this->menu;
    $line = $m->line($m->water);
    ($this->create)($m->order([$line, $line]))->assertStatus(422)->assertJsonValidationErrors(['lines.1.client_uuid']);

    ($this->create)($m->order([$line], ['send' => false]))->assertCreated();
    ($this->create)($m->order([$m->line($m->croissant), $line]))->assertStatus(422)
        ->assertJsonPath('message_key', 'validation.failed')
        ->assertJsonValidationErrors(['lines.1.client_uuid']);
    expect(Order::query()->count())->toBe(1);
})->group('module:orders', 'feature');

it('refuses a line it cannot sell, naming the line, in both languages, and writes nothing', function () {
    $m = $this->menu;
    $b = $this->branch->id;
    $other = Branch::factory()->create();
    $foreign = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $other->id, 'created_by_user_id' => makeUser($other, 'admin')->id]);
    $inactive = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $b, 'is_active' => false]);
    $unpriced = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $b]);
    $noRecipe = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b]);
    app(ItemStations::class)->replace($noRecipe, [$m->kitchenStation->id]);
    $unrouted = Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $b]);
    Recipe::factory()->create(['branch_id' => $b, 'product_item_id' => $unrouted->id, 'version' => 1, 'is_active' => true]);
    $bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $b]);
    $nowhere = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $b]);
    app(ItemStations::class)->replace($nowhere, [$bakery->id]); // routed to a station nobody stocks
    foreach ([$inactive, $noRecipe, $unrouted, $nowhere] as $item) {
        app(PriceHistory::class)->open($item, Money::of('10.00'), CarbonImmutable::now()->subHour()->startOfSecond());
    }

    $cases = [
        'an ingredient' => [$m->line($m->chicken), 'orders.item.not_sellable', 'item_id'],
        'a product of another branch' => [$m->line($foreign), 'orders.item.not_sellable', 'item_id'],
        'an inactive product' => [$m->line($inactive), 'orders.item.not_sellable', 'item_id'],
        'an unknown id' => [['client_uuid' => (string) Str::uuid(), 'item_id' => 999999, 'qty' => '1'], 'orders.item.not_sellable', 'item_id'],
        'a product without a price' => [$m->line($unpriced), 'orders.item.no_price', 'item_id'],
        'a to-order product without a recipe' => [$m->line($noRecipe), 'orders.item.no_recipe', 'item_id'],
        'a to-order product without a station' => [$m->line($unrouted), 'orders.item.no_location', 'item_id'],
        'a product routed to a station without stock' => [$m->line($nowhere), 'orders.item.no_location', 'item_id'],
        'a modifier of another product' => [$m->line($m->sandwich, '1', [$m->large]), 'orders.modifier.not_of_item', 'modifier_ids'],
    ];
    foreach ($cases as $why => [$bad, $key, $field]) {
        $response = ($this->create)($m->order([$m->line($m->water), $bad]));
        $response->assertStatus(422);
        expect($response->json('message_key'))->toBe($key, $why)
            ->and($response->json('line_client_uuid'))->toBe($bad['client_uuid'], $why)
            ->and($response->json('item_id'))->toBe($bad['item_id'], $why)
            ->and($response->json('field'))->toBe("lines.1.{$field}", $why)
            ->and($response->json('message_en'))->toBeString()->not->toBe($key)
            ->and($response->json('message_am'))->toBeString()->not->toBe($key)->not->toBe($response->json('message_en'));
    }
    expect(Order::query()->count())->toBe(0)
        ->and(OrderLine::query()->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'like', 'order.%')->count())->toBe(0);
})->group('module:orders', 'feature');

it('seats a dine-in order at a table of the branch, with the table code as a snapshot; refuses an unknown or inactive table', function () {
    $m = $this->menu;
    $table = DiningTable::factory()->create(['branch_id' => $this->branch->id, 'code' => 'T3', 'name_en' => 'Table 3', 'name_am' => 'ጠረጴዛ 3']);
    $closed = DiningTable::factory()->create(['branch_id' => $this->branch->id, 'code' => 'T9', 'is_active' => false]);
    $other = Branch::factory()->create();
    $foreign = DiningTable::factory()->create(['branch_id' => $other->id, 'code' => 'T1', 'created_by_user_id' => makeUser($other, 'admin')->id]);

    ($this->create)($m->order([$m->line($m->water)], ['type' => 'dine_in', 'dining_table_id' => $table->id]))
        ->assertCreated()
        ->assertJsonPath('type', 'dine_in')
        ->assertJsonPath('table_code', 'T3')
        ->assertJsonPath('dining_table', ['id' => $table->id, 'code' => 'T3', 'name_en' => 'Table 3', 'name_am' => 'ጠረጴዛ 3']);

    foreach ([$closed->id, $foreign->id, 999999] as $id) {
        ($this->create)($m->order([$m->line($m->water)], ['type' => 'dine_in', 'dining_table_id' => $id]))
            ->assertStatus(422)
            ->assertJsonPath('message_key', 'orders.table.unknown')
            ->assertJsonPath('field', 'dining_table_id');
    }
    expect(Order::query()->count())->toBe(1);
})->group('module:orders', 'feature');

it('lets the roles with orders.create order and says where the order came from; refuses the others', function () {
    $m = $this->menu;
    foreach (['admin' => 'admin', 'manager' => 'till', 'cashier' => 'till', 'waiter' => 'waiter_pad'] as $role => $via) {
        $me = loginAs(makeUser($this->branch, $role));
        ($this->create)($m->order([$m->line($m->water)]))->assertCreated()
            ->assertJsonPath('created_via', $via)
            ->assertJsonPath('owner.id', $me->id);
    }
    foreach (['chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        ($this->create)($m->order([$m->line($m->water)]))->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect(Order::query()->count())->toBe(4);
})->group('module:orders', 'feature');

it('makes the waiter acting by short code the owner, and stamps the pad account, the waiter and the device everywhere', function () {
    $m = $this->menu;
    $pad = loginAs(makeUser($this->branch, 'waiter', ['name' => 'Pad 1']));
    $tigist = makeUser($this->branch, 'waiter', ['name' => 'Tigist']);
    $device = Device::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Front pad']);
    $session = app(ActingAsSession::class)->start($tigist, $device);
    $headers = ['X-Acting-Session' => (string) $session->id, 'X-Device-Id' => $device->fingerprint];

    $response = ($this->create)($m->order([$m->line($m->sandwich), $m->line($m->croissant)]), $headers);

    $response->assertCreated()
        ->assertJsonPath('created_via', 'waiter_pad')
        ->assertJsonPath('owner.id', $tigist->id)
        ->assertJsonPath('owner.name', 'Tigist')
        ->assertJsonPath('created_by_user_id', $pad->id)
        ->assertJsonPath('acting_user_id', $tigist->id);
    $order = Order::query()->findOrFail($response->json('id'));
    expect($order->device_id)->toBe($device->id)
        ->and(OrderLine::query()->where('order_id', $order->id)->pluck('acting_user_id')->unique()->all())->toBe([$tigist->id])
        ->and(OrderLine::query()->where('order_id', $order->id)->pluck('device_id')->unique()->all())->toBe([$device->id])
        ->and(OrderEvent::query()->where('order_id', $order->id)->get()->map(fn (OrderEvent $e): array => [$e->user_id, $e->acting_user_id, $e->device_id, $e->owner_user_id])->unique()->values()->all())
        ->toBe([[$pad->id, $tigist->id, $device->id, $tigist->id]]);
    $movements = DB::table('stock_movements')->where('reason', 'reservation')->get();
    expect($movements)->not->toBeEmpty()
        ->and($movements->pluck('user_id')->unique()->all())->toBe([$pad->id])
        ->and($movements->pluck('acting_user_id')->unique()->all())->toBe([$tigist->id])
        ->and($movements->pluck('device_id')->unique()->all())->toBe([$device->id])
        ->and(DB::table('audit_log')->where('subject_id', $order->id)->pluck('acting_user_id')->unique()->all())->toBe([$tigist->id]);
})->group('module:orders', 'feature');

it('refuses a person acting on the pad who may not take orders, and a session that has run out', function () {
    $m = $this->menu;
    loginAs(makeUser($this->branch, 'waiter', ['name' => 'Pad 1']));
    $device = Device::factory()->create(['branch_id' => $this->branch->id]);
    $chef = makeUser($this->branch, 'chef');
    $chefSession = app(ActingAsSession::class)->start($chef, $device);

    ($this->create)($m->order([$m->line($m->water)]), ['X-Acting-Session' => (string) $chefSession->id, 'X-Device-Id' => $device->fingerprint])
        ->assertStatus(403)
        ->assertJsonPath('message_key', 'auth.forbidden');

    $waiterSession = app(ActingAsSession::class)->start(makeUser($this->branch, 'waiter'), $device);
    $this->travelTo(CarbonImmutable::now()->addMinutes(10)->addSecond());
    ($this->create)($m->order([$m->line($m->water)]), ['X-Acting-Session' => (string) $waiterSession->id, 'X-Device-Id' => $device->fingerprint])
        ->assertStatus(401)
        ->assertJsonPath('message_key', 'identity.acting_session.expired');
    expect(Order::query()->count())->toBe(0);
})->group('module:orders', 'feature');

it('replays the same Idempotency-Key with the same body and one order; the same client_uuid under a new key is the same order', function () {
    $m = $this->menu;
    $body = $m->order([$m->line($m->sandwich), $m->line($m->croissant)]);

    assertIdempotent($this, 'POST', '/api/v1/orders', $body, 'orders');
    $id = Order::query()->where('client_uuid', $body['client_uuid'])->value('id');

    $lostKey = ($this->create)($body);
    $lostKey->assertOk()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('order_no', 1);
    expect(Order::query()->count())->toBe(1)
        ->and(OrderLine::query()->count())->toBe(2)
        ->and(DB::table('stock_movements')->where('reason', 'reservation')->count())->toBe(6);

    // the same key with another body is a reuse, not a replay
    $key = idempotencyKey();
    $this->withHeaders($key)->postJson('/api/v1/orders', $m->order([$m->line($m->water)]))->assertCreated();
    $this->withHeaders($key)->postJson('/api/v1/orders', $m->order([$m->line($m->water, '2')]))
        ->assertStatus(422)->assertJsonPath('message_key', 'idempotency.key_reused');
})->group('module:orders', 'feature');

it('leaves the order open next to the shortfall when the send is refused, and sends it later', function () {
    $m = $this->menu;
    stockIn($m->chicken, $m->kitchen, '-1000', MovementReason::Waste);
    $body = $m->order([$m->line($m->sandwich), $m->line($m->water)]);

    $response = ($this->create)($body);

    $response->assertStatus(409)
        ->assertJsonPath('message_key', 'orders.send.shortfall')
        ->assertJsonPath('order.client_uuid', $body['client_uuid'])
        ->assertJsonPath('order.status', 'open')
        ->assertJsonPath('shortfall.0.ingredient_item_id', $m->chicken->id)
        ->assertJsonPath('shortfall.0.qty_short', '126.0000');
    $order = Order::query()->where('client_uuid', $body['client_uuid'])->firstOrFail();
    expect($order->status->value)->toBe('open')
        ->and(OrderEvent::query()->where('order_id', $order->id)->pluck('event')->map->value->all())->toBe(['order.created'])
        ->and(DB::table('stock_movements')->where('reason', 'reservation')->count())->toBe(0);

    withActor($this->admin->id);
    stockIn($m->chicken, $m->kitchen, '500');
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$order->id}/send")->assertOk()->assertJsonPath('order_no', 1);
})->group('module:orders', 'feature');
