<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrdersMenu;

/*
 * POST /api/v1/orders/{order}/cancel (orders.create, own order or stations.all; docs/phases/phase-1-plan.md section 4
 * and section 10): only an unsent (open) order is cancelled, by its owner, without a PIN, with an optional reason.
 * Nothing was reserved before the send, so nothing is released: no Inventory call, no movement. Its lines are voided
 * with it (never deleted). A sent order is voided instead (step 1.3d): 409 orders.order.not_open.
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
    $this->cancel = fn (int $orderId, array $body = []): TestResponse => $this->withHeaders(idempotencyKey())
        ->postJson("/api/v1/orders/{$orderId}/cancel", $body);
});

it('cancels an open order with its reason: the lines are voided, nothing is reserved or released', function () {
    $m = $this->menu;
    $order = ($this->openOrder)([$m->line($m->sandwich), $m->line($m->croissant)]);
    $movements = DB::table('stock_movements')->count();
    $this->travelTo(CarbonImmutable::now()->addMinute()->startOfSecond());

    $response = ($this->cancel)($order['id'], ['reason' => 'The customer left']);

    $response->assertOk()
        ->assertJsonPath('id', $order['id'])
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('order_no', null)
        ->assertJsonPath('cancelled', ['reason' => 'The customer left', 'at' => CarbonImmutable::now()->toIso8601String()])
        ->assertJsonPath('lines.0.status', 'voided')
        ->assertJsonPath('lines.1.status', 'voided')
        ->assertJsonPath('totals.total', '0.00')
        ->assertJsonPath('version', $order['version'] + 1);

    $lineIds = OrderLine::query()->where('order_id', $order['id'])->orderBy('id')->pluck('id')->all();
    $event = OrderEvent::query()->where('order_id', $order['id'])->where('event', 'order.cancelled')->sole();
    expect(DB::table('stock_movements')->count())->toBe($movements, 'nothing was reserved, so nothing is released')
        ->and($event->payload)->toBe(['reason' => 'The customer left', 'line_ids' => $lineIds])
        ->and($event->user_id)->toBe($this->waiter->id)
        ->and(DB::table('audit_log')->where('action', 'order.cancel')->where('subject_id', $order['id'])->count())->toBe(1)
        ->and(OrderLine::query()->where('order_id', $order['id'])->count())->toBe(2, 'nothing is deleted');
})->group('module:orders', 'feature');

it('cancels without a reason', function () {
    $order = ($this->openOrder)([$this->menu->line($this->menu->water)]);

    ($this->cancel)($order['id'])->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelled.reason', null);
})->group('module:orders', 'feature');

it('refuses to cancel a sent order (a sent order is voided), and a cancelled one again; the same key replays', function () {
    $m = $this->menu;
    $sent = ($this->openOrder)([$m->line($m->sandwich)]);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$sent['id']}/send")->assertOk();
    $reserved = stockRow($m->chicken, $m->kitchen)->reserved;

    ($this->cancel)($sent['id'])->assertStatus(409)
        ->assertJsonPath('message_key', 'orders.order.not_open')
        ->assertJsonPath('status', 'sent');
    expect(stockRow($m->chicken, $m->kitchen)->reserved)->toBe($reserved);

    $open = ($this->openOrder)([$m->line($m->water)]);
    $key = idempotencyKey();
    $first = $this->withHeaders($key)->postJson("/api/v1/orders/{$open['id']}/cancel")->assertOk();
    $this->withHeaders($key)->postJson("/api/v1/orders/{$open['id']}/cancel")->assertOk()->assertHeader('Idempotency-Replayed', 'true')
        ->assertExactJson($first->json());
    ($this->cancel)($open['id'])->assertStatus(409)->assertJsonPath('message_key', 'orders.order.not_open')->assertJsonPath('status', 'cancelled');
    expect(OrderEvent::query()->where('order_id', $open['id'])->where('event', 'order.cancelled')->count())->toBe(1);
})->group('module:orders', 'feature');

it('refuses a reason longer than 255 characters', function () {
    $order = ($this->openOrder)([$this->menu->line($this->menu->water)]);

    ($this->cancel)($order['id'], ['reason' => str_repeat('x', 256)])->assertStatus(422)
        ->assertJsonPath('message_key', 'validation.failed')
        ->assertJsonValidationErrors(['reason']);
    expect(Order::query()->findOrFail($order['id'])->status->value)->toBe('open');
})->group('module:orders', 'feature');

it('lets the owner and the stations.all roles cancel; refuses another waiter, the cashier and the floor roles; 404 for another branch', function () {
    $m = $this->menu;
    $order = ($this->openOrder)([$m->line($m->water)]);

    foreach (['waiter', 'cashier'] as $role) {
        loginAs(makeUser($this->branch, $role));
        ($this->cancel)($order['id'])->assertStatus(403)->assertJsonPath('message_key', 'orders.order.not_owner');
    }
    foreach (['chef', 'barista', 'cake_baker', 'bread_baker'] as $role) {
        loginAs(makeUser($this->branch, $role));
        ($this->cancel)($order['id'])->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect(Order::query()->findOrFail($order['id'])->status->value)->toBe('open');

    loginAs(makeUser($this->branch, 'manager'));
    ($this->cancel)($order['id'])->assertOk();
    loginAs($this->waiter);
    $mine = ($this->openOrder)([$m->line($m->water)]);
    ($this->cancel)($mine['id'])->assertOk();

    $other = Branch::factory()->create();
    $theirs = Order::factory()->create(['branch_id' => $other->id, 'created_by_user_id' => makeUser($other, 'waiter')->id]);
    ($this->cancel)($theirs->id)->assertStatus(404)->assertJsonPath('message_key', 'http.not_found');
})->group('module:orders', 'feature');

it('replays the same Idempotency-Key with the same body and cancels once', function () {
    $order = ($this->openOrder)([$this->menu->line($this->menu->water)]);

    assertIdempotent($this, 'POST', "/api/v1/orders/{$order['id']}/cancel", ['reason' => 'Wrong table'], 'audit_log');

    expect(Order::query()->findOrFail($order['id'])->status->value)->toBe('cancelled');
})->group('module:orders', 'feature');
