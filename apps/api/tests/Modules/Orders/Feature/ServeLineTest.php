<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Events\LineServed;
use App\Shared\Live\Broadcasts\OrderLineBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

/*
 * POST /api/v1/order-lines/{line}/served (orders.create; the order's owner or a stations.all holder; plan section 4,
 * section 6 item 3 "1.3c"; workflows-and-screens.md "Waiter taps Served"): a ready line goes ready -> served, stamped
 * with who handed it over and when. Only after ready: a queued or accepted line is refused. Nothing moves in the
 * ledger (station lines were consumed at ready; display-case lines are sold at payment, step 1.4). The order is
 * served once every live line is. LineServed's companion goes to the line's station (the ticket leaves the rail).
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $m = $this->floor->menu;
    $this->order = $this->floor->send([$m->line($m->sandwich), $m->line($m->croissant)]);
    $this->sandwich = $this->floor->lineOf($this->order, $m->sandwich);
    $this->croissant = $this->floor->lineOf($this->order, $m->croissant);
});

it('serves a ready line for its waiter, stamped, writing nothing to the ledger; the order is served with its last line', function () {
    $f = $this->floor;
    $f->act('ready', $this->sandwich['id'], $f->chef)->assertOk()->assertJsonPath('order.status', 'ready');
    $movements = DB::table('stock_movements')->count();
    $this->travelTo(CarbonImmutable::now()->addMinutes(3)->startOfSecond());

    $f->act('served', $this->sandwich['id'], $f->waiter)->assertOk()
        ->assertJsonPath('status', 'served')
        ->assertJsonPath('served_by', $f->waiter->id)
        ->assertJsonPath('served_at', CarbonImmutable::now()->toIso8601String())
        ->assertJsonPath('prepared_by', $f->chef->id)
        ->assertJsonPath('order.status', 'ready', 'the croissant is not handed over yet');
    $f->act('served', $this->croissant['id'], $f->waiter)->assertOk()
        ->assertJsonPath('status', 'served')
        ->assertJsonPath('order.status', 'served')
        ->assertJsonPath('order.totals.balance', '310.00');

    expect(DB::table('stock_movements')->count())->toBe($movements, 'serving writes nothing to the ledger')
        ->and($f->outstanding($f->refOf($this->croissant['client_uuid'])))->toBe([$f->menu->croissant->id => '1.0000'], 'the croissant is sold at payment (step 1.4)')
        ->and(Order::query()->findOrFail($this->order['id'])->status->value)->toBe('served')
        ->and(OrderEvent::query()->where('order_id', $this->order['id'])->orderBy('id')->pluck('event')->map->value->slice(-4)->values()->all())
        ->toBe(['order.ready', 'line.served', 'line.served', 'order.served'])
        ->and(DB::table('audit_log')->where('action', 'order_line.serve')->count())->toBe(2);
})->group('module:orders', 'feature');

it('serves the croissant first: the order is in progress while the kitchen still cooks', function () {
    $f = $this->floor;

    $f->act('served', $this->croissant['id'], $f->waiter)->assertOk()
        ->assertJsonPath('status', 'served')
        ->assertJsonPath('order.status', 'in_progress');
    $f->act('ready', $this->sandwich['id'], $f->chef)->assertOk()->assertJsonPath('order.status', 'ready');
    $f->act('served', $this->sandwich['id'], $f->waiter)->assertOk()->assertJsonPath('order.status', 'served');
})->group('module:orders', 'feature');

it('serves only after ready: a queued, an accepted, a served and a rejected line are refused', function () {
    $f = $this->floor;
    $m = $f->menu;

    $f->act('served', $this->sandwich['id'], $f->waiter)->assertStatus(409)
        ->assertJsonPath('message_key', 'state_machine.illegal_transition')
        ->assertJsonPath('from', 'queued')
        ->assertJsonPath('to', 'served');
    $f->act('accept', $this->sandwich['id'], $f->chef)->assertOk();
    $f->act('served', $this->sandwich['id'], $f->waiter)->assertStatus(409)->assertJsonPath('from', 'accepted');
    $f->act('served', $this->croissant['id'], $f->waiter)->assertOk();
    $f->act('served', $this->croissant['id'], $f->waiter)->assertStatus(409)->assertJsonPath('from', 'served');
    $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'out_of_stock'])->assertOk();
    $f->act('served', $this->sandwich['id'], $f->waiter)->assertStatus(409)->assertJsonPath('from', 'rejected');

    $cart = $f->send([$m->line($m->water)], null, ['send' => false]);
    $f->act('served', $cart['lines'][0]['id'], $f->waiter)->assertStatus(409)->assertJsonPath('message_key', 'orders.order.not_sent');
})->group('module:orders', 'feature');

it('lets the owner and the stations.all roles serve; refuses another waiter, the cashier and the roles without orders.create', function () {
    $f = $this->floor;

    loginAs($another = makeUser($f->branch, 'waiter'));
    $f->act('served', $this->croissant['id'], $another)->assertStatus(403)->assertJsonPath('message_key', 'orders.order.not_owner');
    $f->act('served', $this->croissant['id'], $f->cashier)->assertStatus(403)->assertJsonPath('message_key', 'orders.order.not_owner');
    foreach ([$f->chef, $f->barista, $f->cakeBaker, $f->breadBaker] as $user) {
        $f->act('served', $this->croissant['id'], $user)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect(OrderLine::query()->findOrFail($this->croissant['id'])->status->value)->toBe('ready');

    $f->act('served', $this->croissant['id'], $f->manager)->assertOk()->assertJsonPath('served_by', $f->manager->id);
    $f->act('ready', $this->sandwich['id'], $f->chef)->assertOk();
    $f->act('served', $this->sandwich['id'], $f->admin)->assertOk()->assertJsonPath('served_by', $f->admin->id);
})->group('module:orders', 'feature');

it('stamps the waiter acting by short code on the shared pad, who owns the order', function () {
    $f = $this->floor;
    $m = $f->menu;
    $pad = makeUser($f->branch, 'waiter', ['name' => 'Pad 1']);
    $tigist = makeUser($f->branch, 'waiter', ['name' => 'Tigist']);
    $device = Device::factory()->create(['branch_id' => $f->branch->id, 'name' => 'Front pad']);
    $session = app(ActingAsSession::class)->start($tigist, $device);
    $headers = ['X-Acting-Session' => (string) $session->id, 'X-Device-Id' => $device->fingerprint];
    loginAs($pad);
    $order = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()] + $headers)->postJson('/api/v1/orders', $m->order([$m->line($m->water)]))
        ->assertCreated()->assertJsonPath('owner.id', $tigist->id)->json();

    $f->act('served', $order['lines'][0]['id'], $pad, [], null, $headers)->assertOk()
        ->assertJsonPath('served_by', $tigist->id)
        ->assertJsonPath('order.status', 'served');
    $event = OrderEvent::query()->where('order_line_id', $order['lines'][0]['id'])->where('event', 'line.served')->firstOrFail();
    expect([$event->user_id, $event->acting_user_id, $event->device_id, $event->payload['by_user_id']])->toBe([$pad->id, $tigist->id, $device->id, $tigist->id]);

    // the pad account itself, with no one acting, is not Tigist
    $other = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()] + $headers)->postJson('/api/v1/orders', $m->order([$m->line($m->water)]))->json();
    $this->flushHeaders();
    $f->act('served', $other['lines'][0]['id'], $pad)->assertStatus(403)->assertJsonPath('message_key', 'orders.order.not_owner');
})->group('module:orders', 'feature');

it('replays the same Idempotency-Key with the same body and serves once', function () {
    $f = $this->floor;
    $key = (string) Str::uuid();

    $first = $f->act('served', $this->croissant['id'], $f->waiter, [], $key)->assertOk();
    $counts = [DB::table('audit_log')->count(), DB::table('order_events')->count()];
    $second = $f->act('served', $this->croissant['id'], $f->waiter, [], $key);

    $second->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and([DB::table('audit_log')->count(), DB::table('order_events')->count()])->toBe($counts);
})->group('module:orders', 'feature');

it('dispatches LineServed after the commit; its companion takes the ticket off the station\'s rail, none for a counter line', function () {
    $f = $this->floor;
    $f->act('ready', $this->sandwich['id'], $f->chef)->assertOk();
    $baseline = DB::connection()->transactionLevel();
    $seen = [];
    Event::listen(LineServed::class, function (LineServed $e) use (&$seen): void {
        $seen[] = [$e, DB::connection()->transactionLevel()];
    });
    $companions = [];
    Event::listen(OrderLineBroadcast::class, function (OrderLineBroadcast $b) use (&$companions): void {
        $companions[] = $b;
    });
    Queue::fake();

    $f->act('served', $this->sandwich['id'], $f->waiter)->assertOk();
    $f->act('served', $this->croissant['id'], $f->waiter)->assertOk();

    expect($seen)->toHaveCount(2)
        ->and($seen[0][1])->toBe($baseline)
        ->and($seen[0][0]->payload())->toBe([
            'line_id' => $this->sandwich['id'],
            'order_id' => $this->order['id'],
            'station_id' => $f->menu->kitchenStation->id,
            'by_user_id' => $f->waiter->id,
            'owner_user_id' => $f->waiter->id,
        ])
        ->and($seen[1][0]->payload()['station_id'])->toBeNull()
        ->and($companions)->toHaveCount(1, 'a display-case line is on no station\'s rail')
        ->and(array_map(fn ($c): string => $c->name, $companions[0]->broadcastOn()))->toBe(["private-station.{$f->menu->kitchenStation->id}"])
        ->and($companions[0]->broadcastAs())->toBe('line_served')
        ->and($companions[0]->broadcastWhen())->toBeFalse();
    assertNothingQueuedButStockEvaluation();
})->group('module:orders', 'feature');
