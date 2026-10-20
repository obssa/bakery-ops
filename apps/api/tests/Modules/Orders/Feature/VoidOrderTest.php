<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Events\OrderVoided;
use App\Shared\Live\Broadcasts\OrderVoidedBroadcast;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;

/*
 * POST /api/v1/orders/{order}/void (orders.void_request: admin, manager, cashier asks; the PIN of an orders.void holder,
 * a manager or the admin, approves; docs/phases/phase-1-plan.md sections 4, 5 and 10; ADR 0008): a sent, unpaid order
 * is voided with a required reason in one transaction. Every line that is not rejected or voided yet becomes voided
 * (so the order is not charged), what is still reserved (queued and accepted station lines, display-case lines waiting
 * for their sale) is released through Reservations::releaseMany, and what was consumed at ready stays consumed (no
 * reversal in Phase 1; refunds with reversing movements are Phase 4). The asker and the approver are both recorded
 * (the audit row's actor is the asker, the approver is in its context; the timeline rows carry both). The PIN is
 * never stored. OrderVoided is announced after the commit.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $f = $this->floor;
    $m = $f->menu;
    withActor($f->admin->id);
    $codes = app(ShortCodes::class);
    $codes->set($f->manager, '246802');
    $codes->set($f->admin, '864209');
    $codes->set($f->cashier, '135791');
    $this->managerPin = '246802';
    $this->cashierPin = '135791';
    $this->order = $f->send([$m->line($m->sandwich), $m->line($m->latte), $m->line($m->croissant)]);
    $this->sandwich = $f->lineOf($this->order, $m->sandwich);
    $this->latte = $f->lineOf($this->order, $m->latte);
    $this->croissant = $f->lineOf($this->order, $m->croissant);
    $this->void = function (?int $orderId = null, array $body = [], $as = null, ?string $key = null): TestResponse {
        loginAs($as ?? $this->floor->cashier);

        return $this->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])
            ->postJson('/api/v1/orders/'.($orderId ?? $this->order['id']).'/void', $body + ['reason' => 'Customer left before the food came', 'pin' => $this->managerPin]);
    };
});

it('voids a sent order with a manager PIN: lines voided, reservations released, consumed stays consumed, both people named', function () {
    $f = $this->floor;
    $m = $f->menu;
    // the sandwich was made and served (consumed), the latte is accepted (reserved), the croissant waits for its sale
    $f->act('ready', $this->sandwich['id'], $f->chef)->assertOk();
    $f->act('served', $this->sandwich['id'], $f->waiter)->assertOk();
    $f->act('accept', $this->latte['id'], $f->barista)->assertOk();
    $latteRef = $f->refOf($this->latte['client_uuid']);
    $croissantRef = $f->refOf($this->croissant['client_uuid']);
    $sandwichRef = $f->refOf($this->sandwich['client_uuid']);
    expect($f->outstanding($latteRef))->not->toBe([])->and($f->outstanding($croissantRef))->not->toBe([]);
    $consumed = $f->moved($sandwichRef, 'production_consume', $f->sandwichIngredients(), $m->kitchen);
    $onHand = $f->level($m->chicken, $m->kitchen)->onHand->toString();
    $this->travelTo(CarbonImmutable::now()->addMinutes(7)->startOfSecond());

    $response = ($this->void)(null, ['reason' => '  Customer left before the food came  ']);

    $response->assertOk()
        ->assertJsonPath('id', $this->order['id'])
        ->assertJsonPath('status', 'voided')
        ->assertJsonPath('voided', [
            'reason' => 'Customer left before the food came',
            'by' => $f->cashier->id,
            'approved_by' => $f->manager->id,
            'at' => CarbonImmutable::now()->toIso8601String(),
        ])
        ->assertJsonPath('totals.total', '0.00')
        ->assertJsonPath('totals.balance', '0.00')
        ->assertJsonPath('version', fn (int $v): bool => $v > $this->order['version']);
    expect(array_column($response->json('lines'), 'status'))->toBe(['voided', 'voided', 'voided']);

    expect($f->outstanding($latteRef))->toBe([])->and($f->outstanding($croissantRef))->toBe([]);
    $milk = $f->level($m->milk, $m->bar);
    $croissant = $f->level($m->croissant, $m->display);
    expect($milk->reserved->toString())->toBe('0.0000')
        ->and($croissant->reserved->toString())->toBe('0.0000')
        ->and($croissant->available()->toString())->toBe($croissant->onHand->toString())
        ->and($f->moved($latteRef, 'reservation_release', [$m->milk, $m->espresso], $m->bar))->not->toBe([])
        // consumed lines stay consumed: no release, no reversal, the stock is as the ready left it
        ->and($f->moved($sandwichRef, 'production_consume', $f->sandwichIngredients(), $m->kitchen))->toEqual($consumed)
        ->and($f->moved($sandwichRef, 'reservation_release', $f->sandwichIngredients(), $m->kitchen))->toBe([])
        ->and($f->level($m->chicken, $m->kitchen)->onHand->toString())->toBe($onHand);
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);

    $order = Order::query()->findOrFail($this->order['id']);
    expect($order->void_reason)->toBe('Customer left before the food came')
        ->and([$order->voided_by_user_id, $order->void_approved_by_user_id])->toBe([$f->cashier->id, $f->manager->id])
        ->and($order->voided_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String())
        ->and(OrderLine::query()->where('order_id', $order->id)->pluck('status')->map(fn ($s) => $s->value)->all())->toBe(['voided', 'voided', 'voided'])
        ->and($order->total->amount())->toBe('0.00');
})->group('module:orders', 'feature');

it('lets the manager and the admin ask too, with a manager or admin PIN', function () {
    $f = $this->floor;
    $m = $f->menu;
    $a = $f->send([$m->line($m->latte)]);
    $b = $f->send([$m->line($m->latte)]);
    $c = $f->send([$m->line($m->latte)]);

    ($this->void)($a['id'], ['pin' => '864209'], $f->admin)->assertOk()->assertJsonPath('voided.approved_by', $f->admin->id)->assertJsonPath('voided.by', $f->admin->id);
    ($this->void)($b['id'], ['pin' => $this->managerPin], $f->manager)->assertOk()->assertJsonPath('voided.approved_by', $f->manager->id);
    ($this->void)($c['id'], ['pin' => '864209'], $f->manager)->assertOk()->assertJsonPath('voided.by', $f->manager->id)->assertJsonPath('voided.approved_by', $f->admin->id);
})->group('module:orders', 'feature');

it('refuses the PIN of a cashier (request only), of nobody and of another branch, and writes nothing', function () {
    $f = $this->floor;
    $before = [DB::table('stock_movements')->count(), DB::table('order_events')->count(), DB::table('audit_log')->where('action', 'order.void')->count()];
    $other = actInBranch(Branch::factory()->create());
    withActor(makeUser($other, 'admin')->id);
    $foreign = makeUser($other, 'manager');
    app(ShortCodes::class)->set($foreign, '975310');
    actInBranch($f->branch);

    foreach ([$this->cashierPin, '000000', '975310'] as $pin) {
        ($this->void)(null, ['pin' => $pin])->assertStatus(403)->assertJsonPath('message_key', 'identity.pin.rejected');
    }

    expect([DB::table('stock_movements')->count(), DB::table('order_events')->count(), DB::table('audit_log')->where('action', 'order.void')->count()])->toBe($before)
        ->and(Order::query()->findOrFail($this->order['id'])->status->value)->toBe('sent')
        ->and($f->outstanding($f->refOf($this->sandwich['client_uuid'])))->not->toBe([]);
})->group('module:orders', 'feature');

it('counts wrong PINs toward the lockout: five are refused, the sixth is locked even with the right PIN', function () {
    foreach (range(1, 5) as $try) {
        ($this->void)(null, ['pin' => '00000'.$try])->assertStatus(403)->assertJsonPath('message_key', 'identity.pin.rejected');
    }

    ($this->void)(null, ['pin' => $this->managerPin])->assertStatus(423)->assertJsonPath('message_key', 'identity.short_code.locked');
    expect(Order::query()->findOrFail($this->order['id'])->status->value)->toBe('sent');
})->group('module:orders', 'feature');

it('records the asker as the actor and the approver in the audit row and the timeline, and never stores the PIN', function () {
    $f = $this->floor;
    $f->act('accept', $this->latte['id'], $f->barista)->assertOk();

    ($this->void)(null, ['pin' => $this->managerPin])->assertOk();

    $audit = DB::table('audit_log')->where('action', 'order.void')->where('subject_id', $this->order['id'])->sole();
    $context = json_decode((string) $audit->context, true);
    expect($audit->actor_id)->toBe($f->cashier->id)
        ->and($context)->toMatchArray(['reason' => 'Customer left before the food came', 'approved_by_user_id' => $f->manager->id])
        ->and(json_decode((string) $audit->after, true)['status'])->toBe('voided');

    $voided = OrderEvent::query()->where('order_id', $this->order['id'])->where('event', 'order.voided')->sole();
    expect($voided->user_id)->toBe($f->cashier->id)
        ->and($voided->approved_by_user_id)->toBe($f->manager->id)
        ->and($voided->owner_user_id)->toBe($f->waiter->id)
        ->and($voided->payload)->toBe([
            'reason' => 'Customer left before the food came',
            'by_user_id' => $f->cashier->id,
            'approved_by_user_id' => $f->manager->id,
            'line_ids' => [$this->sandwich['id'], $this->latte['id'], $this->croissant['id']],
        ]);
    $lineEvents = OrderEvent::query()->where('order_id', $this->order['id'])->where('event', 'line.voided')->orderBy('id')->get();
    expect($lineEvents->pluck('order_line_id')->all())->toBe([$this->sandwich['id'], $this->latte['id'], $this->croissant['id']])
        ->and($lineEvents->pluck('approved_by_user_id')->unique()->all())->toBe([$f->manager->id])
        ->and($lineEvents->first()->station_id)->toBe($f->menu->kitchenStation->id);

    foreach ([$this->managerPin, $this->cashierPin] as $pin) {
        expect(json_encode(DB::table('audit_log')->get()))->not->toContain($pin)
            ->and(json_encode(DB::table('order_events')->get()))->not->toContain($pin);
    }
})->group('module:orders', 'feature');

it('replays the same Idempotency-Key with the same body and voids once', function () {
    $key = (string) Str::uuid();

    $first = ($this->void)(null, [], null, $key)->assertOk();
    $counts = [DB::table('stock_movements')->count(), DB::table('audit_log')->count(), DB::table('order_events')->count()];
    $second = ($this->void)(null, [], null, $key);

    $second->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and([DB::table('stock_movements')->count(), DB::table('audit_log')->count(), DB::table('order_events')->count()])->toBe($counts)
        ->and(DB::table('audit_log')->where('action', 'order.void')->count())->toBe(1);

    // a new key is a new request: the order is voided already
    ($this->void)()->assertStatus(409)->assertJsonPath('message_key', 'orders.order.not_voidable')->assertJsonPath('status', 'voided');
})->group('module:orders', 'feature');

it('is for the asking roles only: waiter, chef, barista and the bakers are refused', function () {
    $f = $this->floor;
    foreach ([$f->waiter, $f->chef, $f->barista, $f->cakeBaker, $f->breadBaker] as $user) {
        ($this->void)(null, [], $user)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }

    expect(Order::query()->findOrFail($this->order['id'])->status->value)->toBe('sent');
})->group('module:orders', 'feature');

it('asks for a reason and a PIN, and a PIN of the right shape', function () {
    ($this->void)(null, ['reason' => ''])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed')->assertJsonStructure(['errors' => ['reason']]);
    ($this->void)(null, ['reason' => '   '])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
    ($this->void)(null, ['reason' => str_repeat('x', 256)])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
    ($this->void)(null, ['pin' => ''])->assertStatus(422)->assertJsonStructure(['errors' => ['pin']]);
    ($this->void)(null, ['pin' => 'abcd'])->assertStatus(422)->assertJsonStructure(['errors' => ['pin']]);
    ($this->void)(null, ['pin' => ['2468']])->assertStatus(422)->assertJsonStructure(['errors' => ['pin']]);

    expect(Order::query()->findOrFail($this->order['id'])->status->value)->toBe('sent');
})->group('module:orders', 'feature');

it('refuses what cannot be voided: an open cart, a cancelled or voided order, and a paid, settled or closed one', function () {
    $f = $this->floor;
    $m = $f->menu;
    $cart = $f->send([$m->line($m->sandwich)], null, ['send' => false]);
    ($this->void)($cart['id'])->assertStatus(409)->assertJsonPath('message_key', 'orders.order.not_voidable')->assertJsonPath('status', 'open');

    foreach (['cancelled' => 11, 'voided' => 12] as $status => $no) {
        $order = ['id' => Order::factory()->sent($no)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->waiter->id, 'status' => $status])->id];
        ($this->void)($order['id'])->assertStatus(409)->assertJsonPath('message_key', 'orders.order.not_voidable')->assertJsonPath('status', $status);
    }
    foreach (['paid' => 13, 'closed' => 14] as $status => $no) {
        $order = ['id' => Order::factory()->sent($no)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->waiter->id, 'status' => $status])->id];
        ($this->void)($order['id'])->assertStatus(409)->assertJsonPath('message_key', 'orders.order.paid')->assertJsonPath('status', $status);
    }
    $settled = Order::factory()->sent(15)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->waiter->id, 'settled_at' => CarbonImmutable::now()]);
    ($this->void)($settled->id)->assertStatus(409)->assertJsonPath('message_key', 'orders.order.paid')->assertJsonPath('status', 'sent');

    expect(DB::table('audit_log')->where('action', 'order.void')->count())->toBe(0);
})->group('module:orders', 'feature');

it('does not spend a PIN attempt on an order that cannot be voided', function () {
    $f = $this->floor;
    $m = $f->menu;
    $cart = $f->send([$m->line($m->sandwich)], null, ['send' => false]);
    foreach (range(1, 6) as $try) {
        ($this->void)($cart['id'], ['pin' => '000000'])->assertStatus(409)->assertJsonPath('message_key', 'orders.order.not_voidable');
    }

    ($this->void)()->assertOk();
})->group('module:orders', 'feature');

it('voids an order whose lines were partly rejected: rejected lines stay rejected', function () {
    $f = $this->floor;
    $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'out_of_stock'])->assertOk();

    $response = ($this->void)()->assertOk();

    expect(array_column($response->json('lines'), 'status'))->toBe(['rejected', 'voided', 'voided']);
    $voided = OrderEvent::query()->where('order_id', $this->order['id'])->where('event', 'order.voided')->sole();
    expect($voided->payload['line_ids'])->toBe([$this->latte['id'], $this->croissant['id']])
        ->and(OrderEvent::query()->where('order_id', $this->order['id'])->where('event', 'line.voided')->count())->toBe(2);
})->group('module:orders', 'feature');

it('keeps branches apart: another branch order is a 404 and its manager PIN does not approve', function () {
    $f = $this->floor;
    $other = OrderFloor::open($this);
    withActor($other->admin->id);
    app(ShortCodes::class)->set($other->manager, '112358');
    $theirs = $other->send([$other->menu->line($other->menu->latte)]);
    actInBranch($f->branch);

    ($this->void)($theirs['id'], ['pin' => '112358'], $f->cashier)->assertNotFound();
    ($this->void)(null, ['pin' => '112358'], $f->cashier)->assertStatus(403)->assertJsonPath('message_key', 'identity.pin.rejected');
    expect(Order::query()->withoutGlobalScopes()->findOrFail($theirs['id'])->status->value)->toBe('sent');
})->group('module:orders', 'feature');

it('rolls the whole void back when one of its writes fails: nothing voided, still reserved', function () {
    $f = $this->floor;
    $before = [DB::table('stock_movements')->count(), DB::table('order_events')->count(), DB::table('audit_log')->count()];
    Event::listen('eloquent.creating: '.OrderEvent::class, function (): never {
        throw new RuntimeException('the disk is full');
    });

    ($this->void)()->assertStatus(500);

    expect([DB::table('stock_movements')->count(), DB::table('order_events')->count(), DB::table('audit_log')->count()])->toBe($before)
        ->and(Order::query()->findOrFail($this->order['id'])->status->value)->toBe('sent')
        ->and(OrderLine::query()->where('order_id', $this->order['id'])->where('status', 'voided')->count())->toBe(0)
        ->and($f->outstanding($f->refOf($this->latte['client_uuid'])))->not->toBe([]);
})->group('module:orders', 'feature');

it('dispatches OrderVoided once, after the commit, with its companion on the stations and the owner', function () {
    $f = $this->floor;
    $baseline = DB::connection()->transactionLevel();
    $seen = [];
    Event::listen(OrderVoided::class, function (OrderVoided $e) use (&$seen): void {
        $seen[] = [$e, DB::connection()->transactionLevel()];
    });
    $companions = [];
    Event::listen(OrderVoidedBroadcast::class, function (OrderVoidedBroadcast $b) use (&$companions): void {
        $companions[] = $b;
    });
    Queue::fake();

    ($this->void)(null, ['pin' => '000000'])->assertStatus(403);
    expect($seen)->toBe([], 'a refused void is not announced');
    ($this->void)()->assertOk();

    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toBe($baseline)
        ->and($seen[0][0]->payload())->toBe([
            'order_id' => $this->order['id'],
            'reason' => 'Customer left before the food came',
            'by_user_id' => $f->cashier->id,
            'approved_by_user_id' => $f->manager->id,
            'line_ids' => [$this->sandwich['id'], $this->latte['id'], $this->croissant['id']],
            'station_ids' => [$f->menu->kitchenStation->id, $f->menu->barStation->id],
            'owner_user_id' => $f->waiter->id,
        ])
        ->and($companions)->toHaveCount(1)
        ->and(array_map(fn ($c): string => $c->name, $companions[0]->broadcastOn()))->toBe([
            "private-station.{$f->menu->kitchenStation->id}",
            "private-station.{$f->menu->barStation->id}",
            "private-user.{$f->waiter->id}",
        ])
        ->and($companions[0]->broadcastAs())->toBe('order_voided')
        ->and($companions[0]->broadcastWhen())->toBeFalse();
    Queue::assertNothingPushed();
})->group('module:orders', 'feature');
