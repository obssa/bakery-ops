<?php

declare(strict_types=1);

use App\Modules\Catalog\Services\PriceHistory;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Models\Branch;
use App\Shared\Values\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrdersMenu;

/*
 * PUT /api/v1/orders/{order}/lines (orders.create, own order or stations.all; docs/phases/phase-1-plan.md section 4):
 * the open cart's lines are replaced by the list sent. A line is known by its client UUID: a line sent again keeps its
 * row and takes the new quantity and notes at the price now (its product and modifiers are fixed: a different line
 * needs a new client UUID); a line left out is voided, never deleted (rule 4); a new one is added. Nothing is reserved
 * before the send. Only an open order can change (409 orders.order.not_open).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->menu = OrdersMenu::build($this->branch);
    $this->waiter = loginAs(makeUser($this->branch, 'waiter'));

    $this->openOrder = fn (array $lines): array => $this->withHeaders(idempotencyKey())
        ->postJson('/api/v1/orders', $this->menu->order($lines, ['send' => false]))->assertCreated()->json();
    $this->replace = fn (int $orderId, array $lines): TestResponse => $this->withHeaders(idempotencyKey())
        ->putJson("/api/v1/orders/{$orderId}/lines", ['lines' => $lines]);
});

it('replaces the lines of an open order: a kept line takes its new quantity at the price now, a missing one is voided, a new one is added', function () {
    $m = $this->menu;
    $sandwich = $m->line($m->sandwich, '1', [$m->noOnion]);
    $latte = $m->line($m->latte);
    $order = ($this->openOrder)([$sandwich, $latte]);
    $ids = OrderLine::query()->where('order_id', $order['id'])->orderBy('id')->pluck('id', 'client_uuid');
    $this->travelTo(CarbonImmutable::now()->addMinute()->startOfSecond());
    withActor($this->admin->id);
    app(PriceHistory::class)->open($m->sandwich, Money::of('260.00'), CarbonImmutable::now());
    $croissant = $m->line($m->croissant, '2');

    $response = ($this->replace)($order['id'], [['qty' => '2', 'notes' => 'well done'] + $sandwich, $croissant]);

    $response->assertOk()
        ->assertJsonPath('id', $order['id'])
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('totals.subtotal', '640.00') // 2 x 260 + 2 x 60; the latte is gone
        ->assertJsonPath('version', $order['version'] + 1);
    $lines = collect($response->json('lines'))->keyBy('client_uuid');
    expect($lines[$sandwich['client_uuid']])->toMatchArray(['id' => $ids[$sandwich['client_uuid']], 'qty' => '2.0000', 'unit_price' => '260.00', 'line_total' => '520.00', 'notes' => 'well done', 'status' => 'queued'])
        ->and($lines[$sandwich['client_uuid']]['version'])->toBe(2)
        ->and(collect($lines[$sandwich['client_uuid']]['modifiers'])->pluck('name_en')->all())->toBe(['No onion'])
        ->and($lines[$latte['client_uuid']])->toMatchArray(['id' => $ids[$latte['client_uuid']], 'status' => 'voided'])
        ->and($lines[$croissant['client_uuid']])->toMatchArray(['qty' => '2.0000', 'line_total' => '120.00', 'status' => 'queued']);

    $newId = OrderLine::query()->where('client_uuid', $croissant['client_uuid'])->value('id');
    $event = OrderEvent::query()->where('order_id', $order['id'])->where('event', 'order.lines_replaced')->sole();
    expect($event->payload)->toBe([
        'added' => [$newId],
        'updated' => [$ids[$sandwich['client_uuid']]],
        'removed' => [$ids[$latte['client_uuid']]],
        'total' => '640.00',
    ])
        ->and($event->user_id)->toBe($this->waiter->id)
        ->and(DB::table('audit_log')->where('action', 'order.lines_replace')->where('subject_id', $order['id'])->count())->toBe(1)
        ->and(OrderLine::query()->where('order_id', $order['id'])->count())->toBe(3, 'nothing is deleted')
        ->and(DB::table('stock_movements')->where('reason', 'reservation')->count())->toBe(0);

    // the next send takes only the lines on the order
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$order['id']}/send")->assertOk()
        ->assertJsonPath('totals.total', '640.00');
    expect(DB::table('stock_movements')->where('reservation_ref', OrderLine::query()->findOrFail($ids[$latte['client_uuid']])->reservation_ref)->count())->toBe(0);
})->group('module:orders', 'feature');

it('refuses to change the product or the modifiers of a line under its client_uuid', function () {
    $m = $this->menu;
    $sandwich = $m->line($m->sandwich, '1', [$m->noOnion]);
    $order = ($this->openOrder)([$sandwich]);

    ($this->replace)($order['id'], [['item_id' => $m->latte->id] + $sandwich])->assertStatus(422)
        ->assertJsonPath('message_key', 'orders.line.changed')
        ->assertJsonPath('line_client_uuid', $sandwich['client_uuid'])
        ->assertJsonPath('field', 'lines.0.item_id');
    ($this->replace)($order['id'], [['modifier_ids' => [$m->extraChicken->id]] + $sandwich])->assertStatus(422)
        ->assertJsonPath('message_key', 'orders.line.changed')
        ->assertJsonPath('field', 'lines.0.modifier_ids');
    expect(OrderLine::query()->where('order_id', $order['id'])->sole()->item_id)->toBe($m->sandwich->id);
})->group('module:orders', 'feature');

it('refuses a client_uuid of another order, or of a line already removed from this one', function () {
    $m = $this->menu;
    $theirs = $m->line($m->water);
    ($this->openOrder)([$theirs]);
    $kept = $m->line($m->croissant);
    $removed = $m->line($m->water);
    $order = ($this->openOrder)([$kept, $removed]);
    ($this->replace)($order['id'], [$kept])->assertOk();

    ($this->replace)($order['id'], [$kept, $theirs])->assertStatus(422)
        ->assertJsonPath('message_key', 'validation.failed')
        ->assertJsonValidationErrors(['lines.1.client_uuid']);
    ($this->replace)($order['id'], [$kept, $removed])->assertStatus(422)->assertJsonValidationErrors(['lines.1.client_uuid']);
})->group('module:orders', 'feature');

it('refuses an empty or malformed list, and a line it cannot sell, changing nothing', function () {
    $m = $this->menu;
    $order = ($this->openOrder)([$m->line($m->water)]);
    $version = Order::query()->findOrFail($order['id'])->version;

    ($this->replace)($order['id'], [])->assertStatus(422)->assertJsonValidationErrors(['lines']);
    ($this->replace)($order['id'], [['qty' => '0'] + $m->line($m->water)])->assertStatus(422)->assertJsonValidationErrors(['lines.0.qty']);
    $ingredient = $m->line($m->chicken);
    ($this->replace)($order['id'], [$m->line($m->water), $ingredient])->assertStatus(422)
        ->assertJsonPath('message_key', 'orders.item.not_sellable')
        ->assertJsonPath('line_client_uuid', $ingredient['client_uuid']);

    expect(Order::query()->findOrFail($order['id'])->version)->toBe($version)
        ->and(OrderLine::query()->where('order_id', $order['id'])->count())->toBe(1);
})->group('module:orders', 'feature');

it('refuses to change an order that is no longer open', function () {
    $m = $this->menu;
    $sent = ($this->openOrder)([$m->line($m->water)]);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$sent['id']}/send")->assertOk();
    $cancelled = ($this->openOrder)([$m->line($m->water)]);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$cancelled['id']}/cancel")->assertOk();

    ($this->replace)($sent['id'], [$m->line($m->croissant)])->assertStatus(409)
        ->assertJsonPath('message_key', 'orders.order.not_open')
        ->assertJsonPath('status', 'ready');
    ($this->replace)($cancelled['id'], [$m->line($m->croissant)])->assertStatus(409)
        ->assertJsonPath('message_key', 'orders.order.not_open')
        ->assertJsonPath('status', 'cancelled');
    expect(OrderLine::query()->where('item_id', $m->croissant->id)->count())->toBe(0);
})->group('module:orders', 'feature');

it('lets the owner and the stations.all roles change the cart; refuses another waiter, the cashier and the floor roles; 404 for another branch', function () {
    $m = $this->menu;
    $order = ($this->openOrder)([$m->line($m->water)]);

    foreach (['waiter', 'cashier'] as $role) {
        loginAs(makeUser($this->branch, $role));
        ($this->replace)($order['id'], [$m->line($m->water)])->assertStatus(403)->assertJsonPath('message_key', 'orders.order.not_owner');
    }
    foreach (['chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        ($this->replace)($order['id'], [$m->line($m->water)])->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    foreach (['manager', 'admin'] as $role) {
        loginAs(makeUser($this->branch, $role));
        ($this->replace)($order['id'], [$m->line($m->water)])->assertOk();
    }
    loginAs($this->waiter);
    ($this->replace)($order['id'], [$m->line($m->croissant)])->assertOk();

    $other = Branch::factory()->create();
    $theirs = Order::factory()->create(['branch_id' => $other->id, 'created_by_user_id' => makeUser($other, 'waiter')->id]);
    ($this->replace)($theirs->id, [$m->line($m->water)])->assertStatus(404)->assertJsonPath('message_key', 'http.not_found');
})->group('module:orders', 'feature');

it('replays the same Idempotency-Key with the same body and changes the cart once', function () {
    $m = $this->menu;
    $order = ($this->openOrder)([$m->line($m->water)]);

    assertIdempotent($this, 'PUT', "/api/v1/orders/{$order['id']}/lines", ['lines' => [$m->line($m->croissant, '2'), ['client_uuid' => (string) Str::uuid(), 'item_id' => $m->cake->id, 'qty' => '1']]], 'audit_log');

    expect(OrderLine::query()->where('order_id', $order['id'])->count())->toBe(3)
        ->and(OrderEvent::query()->where('order_id', $order['id'])->where('event', 'order.lines_replaced')->count())->toBe(1);
})->group('module:orders', 'feature');
