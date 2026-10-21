<?php

declare(strict_types=1);

use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Events\LineRejected;
use App\Shared\Live\Broadcasts\OrderLineBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

/*
 * POST /api/v1/order-lines/{line}/reject (orders.accept at the line's station, or stations.all; plan section 4,
 * section 6 item 3 "1.3c", section 10; ADR 0008: reject releases): a station line goes queued|accepted -> rejected with
 * a reason from the fixed bilingual list (ingredient_spoiled, equipment_down, out_of_stock, other; other needs a note),
 * and in the same transaction its reservation is released through Reservations::release, so what it held is
 * available again. The line returns to the waiter as unfulfilled: it is not charged (the order's total drops), the
 * answer and the pad's feed carry the reason in English and Amharic, and LineRejected tells the owner. An order whose
 * every line is rejected is cancelled (nothing to serve or pay).
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $m = $this->floor->menu;
    $this->order = $this->floor->send([$m->line($m->sandwich), $m->line($m->latte), $m->line($m->croissant)]);
    $this->sandwich = $this->floor->lineOf($this->order, $m->sandwich);
    $this->latte = $this->floor->lineOf($this->order, $m->latte);
    $this->croissant = $this->floor->lineOf($this->order, $m->croissant);
    $this->sandwichRef = $this->floor->refOf($this->sandwich['client_uuid']);
});

it('rejects a queued line with a reason: released, available again, not charged, told back in both languages', function () {
    $f = $this->floor;
    $m = $f->menu;
    expect($this->order['totals']['total'])->toBe('430.00');
    $this->travelTo(CarbonImmutable::now()->addMinutes(4)->startOfSecond());

    $response = $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'ingredient_spoiled']);

    $now = CarbonImmutable::now();
    $response->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('line_total', '250.00')
        ->assertJsonPath('rejected', [
            'reason' => 'ingredient_spoiled',
            'note' => null,
            'message_en' => 'An ingredient has spoiled.',
            'message_am' => 'አንድ ግብዓት ተበላሽቷል።',
            'by' => $f->chef->id,
            'at' => $now->toIso8601String(),
        ])
        ->assertJsonPath('order.status', 'sent', 'the latte is still queued: nothing has started')
        ->assertJsonPath('order.totals.total', '180.00')
        ->assertJsonPath('order.totals.balance', '180.00');

    expect($f->moved($this->sandwichRef, 'reservation_release', $f->sandwichIngredients(), $m->kitchen))->toEqual([
        $m->bread->id => '-2.0000', $m->chicken->id => '-126.0000', $m->lettuce->id => '-20.0000', $m->onion->id => '-15.0000', $m->butter->id => '-10.0000',
    ])
        ->and($f->moved($this->sandwichRef, 'production_consume', $f->sandwichIngredients(), $m->kitchen))->toBe([], 'a rejected sandwich deducts nothing')
        ->and($f->outstanding($this->sandwichRef))->toBe([]);
    $chicken = $f->level($m->chicken, $m->kitchen);
    expect([$chicken->onHand->toString(), $chicken->reserved->toString(), $chicken->available()->toString()])->toBe(['1000.0000', '0.0000', '1000.0000'])
        ->and($f->level($m->milk, $m->bar)->reserved->toString())->toBe('220.0000');
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);

    $line = OrderLine::query()->findOrFail($this->sandwich['id']);
    expect([$line->reject_reason?->value, $line->reject_note, $line->rejected_by_user_id])->toBe(['ingredient_spoiled', null, $f->chef->id])
        ->and(Order::query()->findOrFail($this->order['id'])->total->amount())->toBe('180.00');
    $event = OrderEvent::query()->where('order_line_id', $this->sandwich['id'])->where('event', 'line.rejected')->firstOrFail();
    expect($event->station_id)->toBe($m->kitchenStation->id)
        ->and($event->payload)->toMatchArray(['reason' => 'ingredient_spoiled', 'note' => null, 'by_user_id' => $f->chef->id])
        ->and($event->payload['movement_ids'])->toHaveCount(5)
        ->and(json_decode((string) DB::table('audit_log')->where('action', 'order_line.reject')->where('subject_id', $line->id)->value('context'), true))
        ->toBe(['reason' => 'ingredient_spoiled', 'note' => null]);
})->group('module:orders', 'feature');

it('asks for a note when the reason is other, and keeps it', function () {
    $f = $this->floor;

    $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'other'])->assertStatus(422)
        ->assertJsonPath('message_key', 'validation.failed')
        ->assertJsonStructure(['errors' => ['note']]);
    $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'other', 'note' => '   '])->assertStatus(422)->assertJsonStructure(['errors' => ['note']]);
    $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'other', 'note' => str_repeat('x', 501)])->assertStatus(422)->assertJsonStructure(['errors' => ['note']]);
    $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'too_busy'])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
    $f->act('reject', $this->sandwich['id'], $f->chef, [])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
    expect(OrderLine::query()->findOrFail($this->sandwich['id'])->status->value)->toBe('queued')
        ->and($f->outstanding($this->sandwichRef))->not->toBe([]);

    $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'other', 'note' => '  The grill caught fire  '])->assertOk()
        ->assertJsonPath('rejected.reason', 'other')
        ->assertJsonPath('rejected.note', 'The grill caught fire')
        ->assertJsonPath('rejected.message_en', 'Another reason (see the note).');
})->group('module:orders', 'feature');

it('rejects an accepted line too, and refuses a line that is ready, served or rejected already', function () {
    $f = $this->floor;
    $f->act('accept', $this->latte['id'], $f->barista)->assertOk();

    $f->act('reject', $this->latte['id'], $f->barista, ['reason' => 'equipment_down'])->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('accepted_by', $f->barista->id)
        ->assertJsonPath('order.status', 'in_progress', 'started orders never go back to sent');
    $f->act('reject', $this->latte['id'], $f->barista, ['reason' => 'equipment_down'])->assertStatus(409)->assertJsonPath('from', 'rejected');

    $f->act('ready', $this->sandwich['id'], $f->chef)->assertOk();
    $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'out_of_stock'])->assertStatus(409)
        ->assertJsonPath('message_key', 'state_machine.illegal_transition')
        ->assertJsonPath('from', 'ready')
        ->assertJsonPath('to', 'rejected');
    $f->act('served', $this->sandwich['id'], $f->waiter)->assertOk();
    $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'out_of_stock'])->assertStatus(409)->assertJsonPath('from', 'served');
    expect($f->moved($this->sandwichRef, 'production_consume', $f->sandwichIngredients(), $f->menu->kitchen))->toHaveCount(5, 'what was made stays consumed');
})->group('module:orders', 'feature');

it('cancels an order whose every line is rejected, with everything released and nothing to pay', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich), $m->line($m->latte)]);
    $sandwich = $f->lineOf($order, $m->sandwich);
    $latte = $f->lineOf($order, $m->latte);
    $f->act('accept', $sandwich['id'], $f->chef)->assertOk();

    $f->act('reject', $sandwich['id'], $f->chef, ['reason' => 'ingredient_spoiled'])->assertOk()->assertJsonPath('order.status', 'in_progress');
    $response = $f->act('reject', $latte['id'], $f->barista, ['reason' => 'equipment_down']);

    $response->assertOk()
        ->assertJsonPath('order.status', 'cancelled')
        ->assertJsonPath('order.totals.total', '0.00');
    $after = Order::query()->findOrFail($order['id']);
    expect($after->status->value)->toBe('cancelled')
        ->and($after->cancelled_at)->not->toBeNull()
        ->and($after->cancel_reason)->toBeNull()
        ->and($f->outstanding($f->refOf($sandwich['client_uuid'])))->toBe([])
        ->and($f->outstanding($f->refOf($latte['client_uuid'])))->toBe([])
        ->and(OrderEvent::query()->where('order_id', $order['id'])->where('event', 'order.cancelled')->firstOrFail()->payload)
        ->toBe(['reason' => null, 'cause' => 'every_line_rejected', 'line_ids' => [$sandwich['id'], $latte['id']]]);

    // the first order's lines still hold their own reservations
    expect($f->level($m->chicken, $m->kitchen)->reserved->toString())->toBe('126.0000')
        ->and($f->level($m->milk, $m->bar)->reserved->toString())->toBe('220.0000');
})->group('module:orders', 'feature');

it('makes the order ready when the last station line is rejected and only the croissant is left', function () {
    $f = $this->floor;
    $f->act('ready', $this->latte['id'], $f->barista)->assertOk()->assertJsonPath('order.status', 'in_progress');

    $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'out_of_stock'])->assertOk()
        ->assertJsonPath('order.status', 'ready')
        ->assertJsonPath('order.totals.total', '180.00');
})->group('module:orders', 'feature');

it('gives back what it held: the next order for the last chicken can be sent once the first one is rejected', function () {
    $f = $this->floor;
    $m = $f->menu;
    // the first order's sandwich holds 126 g; leave exactly what one more sandwich needs short
    withActor($f->admin->id);
    stockIn($m->chicken, $m->kitchen, '-800', MovementReason::Waste); // 200 g on hand, 126 held, 74 free
    $cart = $f->send([$m->line($m->sandwich)], null, ['send' => false]);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$cart['id']}/send")
        ->assertStatus(409)->assertJsonPath('shortfall.0.ingredient_item_id', $m->chicken->id);

    $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'ingredient_spoiled'])->assertOk();

    loginAs($f->waiter);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$cart['id']}/send")->assertOk()->assertJsonPath('status', 'sent');
    expect($f->level($m->chicken, $m->kitchen)->reserved->toString())->toBe('126.0000');
})->group('module:orders', 'feature');

it('refuses the wrong station, a chef assigned nowhere and the roles without orders.accept, and releases nothing', function () {
    $f = $this->floor;
    $body = ['reason' => 'out_of_stock'];

    $f->act('reject', $this->sandwich['id'], $f->barista, $body)->assertStatus(403)->assertJsonPath('message_key', 'orders.station.forbidden');
    $f->act('reject', $this->latte['id'], $f->chef, $body)->assertStatus(403)->assertJsonPath('message_key', 'orders.station.forbidden');
    $f->act('reject', $this->sandwich['id'], $f->idleChef, $body)->assertStatus(403)->assertJsonPath('message_key', 'orders.station.forbidden');
    foreach ([$f->waiter, $f->cashier, $f->cakeBaker, $f->breadBaker] as $user) {
        $f->act('reject', $this->sandwich['id'], $user, $body)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    $f->act('reject', $this->croissant['id'], $f->chef, $body)->assertStatus(403)->assertJsonPath('message_key', 'orders.station.forbidden');

    expect($f->outstanding($this->sandwichRef))->toHaveCount(5);
    $f->act('reject', $this->sandwich['id'], $f->admin, $body)->assertOk()->assertJsonPath('rejected.by', $f->admin->id);
})->group('module:orders', 'feature');

it('replays the same Idempotency-Key with the same body and releases once', function () {
    $f = $this->floor;
    $key = (string) Str::uuid();
    $body = ['reason' => 'equipment_down'];

    $first = $f->act('reject', $this->sandwich['id'], $f->chef, $body, $key)->assertOk();
    $counts = [DB::table('stock_movements')->count(), DB::table('audit_log')->count(), DB::table('order_events')->count()];
    $second = $f->act('reject', $this->sandwich['id'], $f->chef, $body, $key);

    $second->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and([DB::table('stock_movements')->count(), DB::table('audit_log')->count(), DB::table('order_events')->count()])->toBe($counts)
        ->and($f->moved($this->sandwichRef, 'reservation_release', $f->sandwichIngredients(), $f->menu->kitchen))->toHaveCount(5);
})->group('module:orders', 'feature');

it('dispatches LineRejected once, after the commit, with the reason, to the owner and the station', function () {
    $f = $this->floor;
    $baseline = DB::connection()->transactionLevel();
    $seen = [];
    Event::listen(LineRejected::class, function (LineRejected $e) use (&$seen): void {
        $seen[] = [$e, DB::connection()->transactionLevel()];
    });
    $companions = [];
    Event::listen(OrderLineBroadcast::class, function (OrderLineBroadcast $b) use (&$companions): void {
        $companions[] = $b;
    });
    Queue::fake();

    $f->act('reject', $this->sandwich['id'], $f->chef, ['reason' => 'out_of_stock'])->assertOk();

    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toBe($baseline)
        ->and($seen[0][0]->payload())->toBe([
            'line_id' => $this->sandwich['id'],
            'order_id' => $this->order['id'],
            'station_id' => $f->menu->kitchenStation->id,
            'by_user_id' => $f->chef->id,
            'owner_user_id' => $f->waiter->id,
            'reason' => 'out_of_stock',
        ])
        ->and($companions)->toHaveCount(1)
        ->and(array_map(fn ($c): string => $c->name, $companions[0]->broadcastOn()))->toBe(["private-station.{$f->menu->kitchenStation->id}", "private-user.{$f->waiter->id}"])
        ->and($companions[0]->broadcastAs())->toBe('line_rejected')
        ->and($companions[0]->broadcastWith()['payload']['reason'])->toBe('out_of_stock')
        ->and($companions[0]->broadcastWhen())->toBeFalse();
    assertNothingQueuedButStockEvaluation();
})->group('module:orders', 'feature');
