<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Events\LineReady;
use App\Shared\Live\Broadcasts\OrderLineBroadcast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

/*
 * POST /api/v1/order-lines/{line}/ready (orders.accept at the line's station, or stations.all; plan section 4,
 * section 6 item 3 "1.3c"; rule 3; ADR 0008: consume at READY, not at send). A station line goes queued|accepted ->
 * ready (the station may ready a line it never accepted), stamped with who made it and when, and in the SAME
 * transaction its reservation becomes `production_consume` through Inventory's Reservations::consume, exactly once:
 * one row per ingredient carrying the line's ref, on_hand and reserved both down by what was reserved, so available
 * does not move. A replay (same key) or a second ready consumes nothing more. The order follows (OrderProgress:
 * ready once every live line is ready). The ledger is read through Inventory's contracts only (Stock, Reservations).
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

it('readies an accepted line and consumes its reservation as production_consume, one row per ingredient, in base units', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->act('accept', $this->sandwich['id'], $f->chef)->assertOk();
    $held = $f->outstanding($this->sandwichRef);
    expect($held)->toEqual([$m->bread->id => '2.0000', $m->chicken->id => '126.0000', $m->lettuce->id => '20.0000', $m->onion->id => '15.0000', $m->butter->id => '10.0000']);
    $chicken = $f->level($m->chicken, $m->kitchen);
    expect([$chicken->onHand->toString(), $chicken->reserved->toString(), $chicken->available()->toString()])->toBe(['1000.0000', '126.0000', '874.0000']);
    $this->travelTo(CarbonImmutable::now()->addMinutes(7)->startOfSecond());

    $response = $f->act('ready', $this->sandwich['id'], $f->chef);

    $now = CarbonImmutable::now();
    $response->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('ready_at', $now->toIso8601String())
        ->assertJsonPath('accepted_by', $f->chef->id)
        ->assertJsonPath('prepared_by', $f->chef->id)
        ->assertJsonPath('order.status', 'in_progress', 'the latte is still queued at the bar');

    expect($f->moved($this->sandwichRef, 'production_consume', $f->sandwichIngredients(), $m->kitchen))->toEqual([
        $m->bread->id => '-2.0000', $m->chicken->id => '-126.0000', $m->lettuce->id => '-20.0000', $m->onion->id => '-15.0000', $m->butter->id => '-10.0000',
    ], 'exactly the reservation, one row per ingredient, carrying the ref')
        ->and($f->outstanding($this->sandwichRef))->toBe([], 'the reservation is settled')
        ->and(array_map(fn ($mv): string => $mv->reason->value, $f->movementsOf($this->sandwichRef, $f->sandwichIngredients(), $m->kitchen)))
        ->toBe(['reservation', 'reservation', 'reservation', 'reservation', 'reservation', 'production_consume', 'production_consume', 'production_consume', 'production_consume', 'production_consume']);
    $chicken = $f->level($m->chicken, $m->kitchen);
    expect([$chicken->onHand->toString(), $chicken->reserved->toString(), $chicken->available()->toString()])->toBe(['874.0000', '0.0000', '874.0000'])
        ->and($f->level($m->milk, $m->bar)->reserved->toString())->toBe('220.0000', 'the bar line is untouched')
        ->and($f->outstanding($f->refOf($this->latte['client_uuid'])))->not->toBe([]);
    $oracle = ledgerOracle($f->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);

    // the consumption carries who made it: the chef's account, at no station or device of record here
    foreach ($f->movementsOf($this->sandwichRef, $f->sandwichIngredients(), $m->kitchen) as $movement) {
        if ($movement->reason->value === 'production_consume') {
            expect($movement->userId)->toBe($f->chef->id);
        }
    }
    $event = OrderEvent::query()->where('order_line_id', $this->sandwich['id'])->where('event', 'line.ready')->firstOrFail();
    expect($event->station_id)->toBe($m->kitchenStation->id)
        ->and($event->payload['by_user_id'])->toBe($f->chef->id)
        ->and($event->payload['movement_ids'])->toHaveCount(5)
        ->and(DB::table('audit_log')->where('action', 'order_line.ready')->where('subject_id', $this->sandwich['id'])->count())->toBe(1);
})->group('module:orders', 'feature');

it('readies a line the station never accepted: no accept stamp, the maker stamped', function () {
    $f = $this->floor;

    $f->act('ready', $this->sandwich['id'], $f->chef)->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('accepted_by', null)
        ->assertJsonPath('accepted_at', null)
        ->assertJsonPath('prepared_by', $f->chef->id)
        ->assertJsonPath('order.status', 'in_progress');

    expect($f->outstanding($this->sandwichRef))->toBe([]);
})->group('module:orders', 'feature');

it('makes the order ready when its last station line is ready (the croissant was ready at send)', function () {
    $f = $this->floor;
    $f->act('ready', $this->sandwich['id'], $f->chef)->assertOk()->assertJsonPath('order.status', 'in_progress');

    $f->act('ready', $this->latte['id'], $f->barista)->assertOk()->assertJsonPath('order.status', 'ready');

    expect(Order::query()->findOrFail($this->order['id'])->status->value)->toBe('ready')
        ->and(OrderEvent::query()->where('order_id', $this->order['id'])->where('event', '!=', 'order.created')->orderBy('id')->pluck('event')->map->value->all())->toBe([
            'order.sent', 'line.queued', 'line.queued', 'line.ready',
            'line.ready', 'order.in_progress',
            'line.ready', 'order.ready',
        ])
        ->and($f->moved($f->refOf($this->latte['client_uuid']), 'production_consume', [$f->menu->espresso, $f->menu->milk], $f->menu->bar))
        ->toBe([$f->menu->espresso->id => '-18.0000', $f->menu->milk->id => '-220.0000']);
})->group('module:orders', 'feature');

it('consumes what the modifiers made of the recipe: extra chicken, no onion', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich, '1', [$m->extraChicken, $m->noOnion])]);
    $line = $order['lines'][0];
    $ref = $f->refOf($line['client_uuid']);

    $f->act('ready', $line['id'], $f->chef)->assertOk();

    expect($f->moved($ref, 'production_consume', $f->sandwichIngredients(), $m->kitchen))->toEqual([
        $m->bread->id => '-2.0000', $m->chicken->id => '-176.0000', $m->lettuce->id => '-20.0000', $m->butter->id => '-10.0000',
    ]);
})->group('module:orders', 'feature');

it('consumes once: the same key replays the answer, a second ready under a new key is refused', function () {
    $f = $this->floor;
    $m = $f->menu;
    $key = (string) Str::uuid();

    $first = $f->act('ready', $this->sandwich['id'], $f->chef, [], $key)->assertOk();
    $counts = [DB::table('stock_movements')->count(), DB::table('audit_log')->count(), DB::table('order_events')->count()];
    $replay = $f->act('ready', $this->sandwich['id'], $f->chef, [], $key);
    $again = $f->act('ready', $this->sandwich['id'], $f->chef);

    $replay->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect($replay->json())->toBe($first->json());
    $again->assertStatus(409)
        ->assertJsonPath('message_key', 'state_machine.illegal_transition')
        ->assertJsonPath('from', 'ready')
        ->assertJsonPath('to', 'ready');
    expect([DB::table('stock_movements')->count(), DB::table('audit_log')->count(), DB::table('order_events')->count()])->toBe($counts)
        ->and($f->moved($this->sandwichRef, 'production_consume', $f->sandwichIngredients(), $m->kitchen))->toHaveCount(5)
        ->and($f->level($m->chicken, $m->kitchen)->onHand->toString())->toBe('874.0000');
})->group('module:orders', 'feature');

it('refuses the wrong station, a chef assigned nowhere and the roles without orders.accept, and consumes nothing', function () {
    $f = $this->floor;

    $f->act('ready', $this->sandwich['id'], $f->barista)->assertStatus(403)->assertJsonPath('message_key', 'orders.station.forbidden');
    $f->act('ready', $this->latte['id'], $f->chef)->assertStatus(403)->assertJsonPath('message_key', 'orders.station.forbidden');
    $f->act('ready', $this->sandwich['id'], $f->idleChef)->assertStatus(403)->assertJsonPath('message_key', 'orders.station.forbidden');
    foreach ([$f->waiter, $f->cashier, $f->cakeBaker, $f->breadBaker] as $user) {
        $f->act('ready', $this->sandwich['id'], $user)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }

    expect($f->outstanding($this->sandwichRef))->not->toBe([])
        ->and(OrderLine::query()->findOrFail($this->sandwich['id'])->status->value)->toBe('queued');
    $f->act('ready', $this->sandwich['id'], $f->manager)->assertOk()->assertJsonPath('prepared_by', $f->manager->id);
})->group('module:orders', 'feature');

it('refuses a display-case line (ready at send), a rejected line and a line of an unsent cart', function () {
    $f = $this->floor;
    $m = $f->menu;

    $f->act('ready', $this->croissant['id'], $f->admin)->assertStatus(409)->assertJsonPath('from', 'ready');
    $f->act('reject', $this->latte['id'], $f->barista, ['reason' => 'out_of_stock'])->assertOk();
    $f->act('ready', $this->latte['id'], $f->barista)->assertStatus(409)->assertJsonPath('from', 'rejected');
    $cart = $f->send([$m->line($m->sandwich)], null, ['send' => false]);
    $f->act('ready', $cart['lines'][0]['id'], $f->chef)->assertStatus(409)->assertJsonPath('message_key', 'orders.order.not_sent');

    expect($f->moved($f->refOf($this->latte['client_uuid']), 'production_consume', [$m->espresso, $m->milk], $m->bar))->toBe([], 'a released reservation is never consumed');
})->group('module:orders', 'feature');

it('readies in one database transaction: the line, the order, the timeline, the audit row and the consumption', function () {
    $f = $this->floor;
    loginAs($f->chef);
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

    $f->act('ready', $this->sandwich['id'], $f->chef)->assertOk();

    $readyWrites = collect($writes)->reject(fn (array $w): bool => $w[0] === 'idempotency_keys');
    expect($readyWrites->pluck(0)->unique()->sort()->values()->all())
        ->toBe(['audit_log', 'order_events', 'order_lines', 'orders', 'stock_balances', 'stock_movements'])
        ->and($readyWrites->pluck(1)->contains(null))->toBeFalse('no write of the ready runs outside a transaction')
        ->and($readyWrites->pluck(1)->unique()->values()->all())->toHaveCount(1, 'one BEGIN: the consumption is in the ready\'s own transaction');
})->group('module:orders', 'feature');

it('rolls the whole ready back when one of its writes fails: nothing consumed, the line still accepted', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->act('accept', $this->sandwich['id'], $f->chef)->assertOk();
    $before = [DB::table('stock_movements')->count(), DB::table('order_events')->count(), DB::table('audit_log')->count()];
    // the timeline is written after the consumption: make it fail
    Event::listen('eloquent.creating: '.OrderEvent::class, function (): never {
        throw new RuntimeException('the disk is full');
    });

    $f->act('ready', $this->sandwich['id'], $f->chef)->assertStatus(500);

    expect([DB::table('stock_movements')->count(), DB::table('order_events')->count(), DB::table('audit_log')->count()])->toBe($before)
        ->and(OrderLine::query()->findOrFail($this->sandwich['id'])->status->value)->toBe('accepted')
        ->and($f->outstanding($this->sandwichRef))->toHaveCount(5)
        ->and($f->level($m->chicken, $m->kitchen)->onHand->toString())->toBe('1000.0000');
})->group('module:orders', 'feature');

it('dispatches LineReady once, after the commit, with its companion on the owner and the station', function () {
    $f = $this->floor;
    $baseline = DB::connection()->transactionLevel();
    $seen = [];
    Event::listen(LineReady::class, function (LineReady $e) use (&$seen): void {
        $seen[] = [$e, DB::connection()->transactionLevel()];
    });
    $companions = [];
    Event::listen(OrderLineBroadcast::class, function (OrderLineBroadcast $b) use (&$companions): void {
        $companions[] = $b;
    });
    Queue::fake();

    $f->act('ready', $this->sandwich['id'], $f->chef)->assertOk();

    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toBe($baseline)
        ->and($seen[0][0]->payload())->toBe([
            'line_id' => $this->sandwich['id'],
            'order_id' => $this->order['id'],
            'station_id' => $f->menu->kitchenStation->id,
            'by_user_id' => $f->chef->id,
            'owner_user_id' => $f->waiter->id,
        ])
        ->and($companions)->toHaveCount(1)
        ->and(array_map(fn ($c): string => $c->name, $companions[0]->broadcastOn()))->toBe(["private-station.{$f->menu->kitchenStation->id}", "private-user.{$f->waiter->id}"])
        ->and($companions[0]->broadcastAs())->toBe('line_ready')
        ->and($companions[0]->broadcastWhen())->toBeFalse();
    Queue::assertNothingPushed();
})->group('module:orders', 'feature');
