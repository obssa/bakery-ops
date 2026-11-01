<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Models\Branch;
use App\Shared\Models\IdempotencyKey;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrdersMenu;

/*
 * Rule 7 / ADR 0003 for the offline outbox (Phase 5 step 5.2a, plan section 4.2): a tablet replays a queued write with its original
 * Idempotency-Key and client_uuid, possibly after the key's stored answer has expired (bakery.idempotency.ttl_hours) because it stayed
 * offline that long. The key is then forgotten and the write runs again: the order's client_uuid still refuses a second order, and the
 * answer names the order that exists. Nothing more is written (no second order, no line, no reservation).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->menu = OrdersMenu::build($this->branch);
    $this->waiter = loginAs(makeUser($this->branch, 'waiter'));
});

it('answers an order replayed after its key expired with the order that exists, and writes nothing more', function () {
    $m = $this->menu;
    $body = $m->order([$m->line($m->sandwich), $m->line($m->croissant)]);
    $key = idempotencyKey();

    $id = $this->withHeaders($key)->postJson('/api/v1/orders', $body)->assertCreated()->json('id');
    $movements = DB::table('stock_movements')->count();
    $stored = IdempotencyKey::query()->where('key', $key['Idempotency-Key'])->sole();

    $this->travelTo($stored->expires_at->addSecond());

    $this->withHeaders($key)->postJson('/api/v1/orders', $body)
        ->assertOk()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('client_uuid', $body['client_uuid'])
        ->assertJsonPath('status', 'sent');
    expect(Order::query()->where('client_uuid', $body['client_uuid'])->count())->toBe(1)
        ->and(Order::query()->count())->toBe(1)
        ->and(OrderLine::query()->count())->toBe(2)
        ->and(DB::table('stock_movements')->count())->toBe($movements);

    // the expired answer gave way to the replay's own, which the next replay is answered from
    $renewed = IdempotencyKey::query()->where('key', $key['Idempotency-Key'])->sole();
    expect($renewed->expires_at->isFuture())->toBeTrue()
        ->and($renewed->response_status)->toBe(200);
    $this->withHeaders($key)->postJson('/api/v1/orders', $body)->assertOk()->assertJsonPath('id', $id);
    expect(Order::query()->count())->toBe(1);
})->group('invariants', 'module:orders');
