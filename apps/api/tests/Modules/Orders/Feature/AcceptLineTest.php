<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Shared\Events\LineAccepted;
use App\Shared\Live\Broadcasts\OrderLineBroadcast;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

/*
 * POST /api/v1/order-lines/{line}/accept (orders.accept at the line's station, or stations.all; docs/phases/
 * phase-1-plan.md section 4, section 6 item 3 "1.3c"; workflows-and-screens.md, "Station handling"): the ticket's
 * line goes queued -> accepted, stamped with who is on it (accepted_by and prepared_by: the person, the one acting by
 * short code on a shared KDS when there is one) and when. Nothing moves in the ledger (ADR 0008: accept writes
 * nothing). The order follows its lines (OrderProgress: sent -> in_progress). One AuditedAction, the order_events row
 * line.accepted, and after the commit LineAccepted with its companion on the station's channel and the owner's (the
 * pad learns who is on it). The answer is the line and the order's status.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $m = $this->floor->menu;
    $this->order = $this->floor->send([$m->line($m->sandwich), $m->line($m->latte), $m->line($m->croissant)]);
    $this->sandwich = $this->floor->lineOf($this->order, $m->sandwich);
    $this->latte = $this->floor->lineOf($this->order, $m->latte);
    $this->croissant = $this->floor->lineOf($this->order, $m->croissant);
});

it('accepts a kitchen line for the chef: stamped, the order in progress, the pad told who is on it, nothing moved in the ledger', function () {
    $f = $this->floor;
    $this->travelTo(CarbonImmutable::now()->addMinutes(2)->startOfSecond());
    $movements = DB::table('stock_movements')->count();
    $lastEvent = (int) DB::table('order_events')->max('id');

    $response = $f->act('accept', $this->sandwich['id'], $f->chef);

    $now = CarbonImmutable::now();
    $response->assertOk()
        ->assertJsonPath('id', $this->sandwich['id'])
        ->assertJsonPath('status', 'accepted')
        ->assertJsonPath('accepted_by', $f->chef->id)
        ->assertJsonPath('prepared_by', $f->chef->id)
        ->assertJsonPath('accepted_at', $now->toIso8601String())
        ->assertJsonPath('ready_at', null)
        ->assertJsonPath('station.id', $f->menu->kitchenStation->id)
        ->assertJsonPath('version', $this->sandwich['version'] + 1)
        ->assertJsonPath('order.id', $this->order['id'])
        ->assertJsonPath('order.status', 'in_progress')
        ->assertJsonPath('order.version', $this->order['version'] + 1)
        ->assertJsonPath('order.totals.total', $this->order['totals']['total']);

    $line = OrderLine::query()->findOrFail($this->sandwich['id']);
    expect($line->status->value)->toBe('accepted')
        ->and($line->accepted_by_user_id)->toBe($f->chef->id)
        ->and($line->prepared_by_user_id)->toBe($f->chef->id)
        ->and($line->accepted_at?->toIso8601String())->toBe($now->toIso8601String())
        ->and(Order::query()->findOrFail($this->order['id'])->status->value)->toBe('in_progress')
        ->and(DB::table('stock_movements')->count())->toBe($movements, 'accept writes nothing to the ledger (ADR 0008)')
        ->and($f->outstanding($f->refOf($this->sandwich['client_uuid'])))->not->toBe([], 'the reservation still holds');

    $events = OrderEvent::query()->where('id', '>', $lastEvent)->orderBy('id')->get();
    expect($events->map(fn (OrderEvent $e): array => [$e->event->value, $e->order_line_id, $e->station_id, $e->user_id, $e->owner_user_id])->all())->toBe([
        ['line.accepted', $this->sandwich['id'], $f->menu->kitchenStation->id, $f->chef->id, $f->waiter->id],
        ['order.in_progress', null, null, $f->chef->id, $f->waiter->id],
    ])
        ->and($events->first()->payload)->toMatchArray(['by_user_id' => $f->chef->id])
        ->and(DB::table('audit_log')->where('action', 'order_line.accept')->where('subject_id', $this->sandwich['id'])->count())->toBe(1);
})->group('module:orders', 'feature');

it('lets the barista accept the bar line; the order, already in progress, stays so with no second order event', function () {
    $f = $this->floor;
    $f->act('accept', $this->sandwich['id'], $f->chef)->assertOk();

    $f->act('accept', $this->latte['id'], $f->barista)->assertOk()
        ->assertJsonPath('status', 'accepted')
        ->assertJsonPath('accepted_by', $f->barista->id)
        ->assertJsonPath('order.status', 'in_progress');

    expect(OrderEvent::query()->where('order_id', $this->order['id'])->where('event', 'order.in_progress')->count())->toBe(1);
})->group('module:orders', 'feature');

it('refuses a station that is not the line\'s, a chef assigned nowhere, and the roles without orders.accept; stations.all works anywhere', function () {
    $f = $this->floor;

    $f->act('accept', $this->sandwich['id'], $f->barista)->assertStatus(403)->assertJsonPath('message_key', 'orders.station.forbidden');
    $f->act('accept', $this->latte['id'], $f->chef)->assertStatus(403)->assertJsonPath('message_key', 'orders.station.forbidden');
    $f->act('accept', $this->sandwich['id'], $f->idleChef)->assertStatus(403)->assertJsonPath('message_key', 'orders.station.forbidden');
    foreach ([$f->waiter, $f->cashier, $f->cakeBaker, $f->breadBaker] as $user) {
        $f->act('accept', $this->sandwich['id'], $user)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect(OrderLine::query()->findOrFail($this->sandwich['id'])->status->value)->toBe('queued')
        ->and(DB::table('audit_log')->where('action', 'order_line.accept')->count())->toBe(0);

    $f->act('accept', $this->sandwich['id'], $f->manager)->assertOk()->assertJsonPath('accepted_by', $f->manager->id);
    $f->act('accept', $this->latte['id'], $f->admin)->assertOk()->assertJsonPath('accepted_by', $f->admin->id);
    // a station-less (display-case) line is nobody's station: only stations.all reaches it, and it is ready already
    $f->act('accept', $this->croissant['id'], $f->chef)->assertStatus(403)->assertJsonPath('message_key', 'orders.station.forbidden');
    $f->act('accept', $this->croissant['id'], $f->manager)->assertStatus(409)->assertJsonPath('message_key', 'state_machine.illegal_transition');
})->group('module:orders', 'feature');

it('stamps the cook acting by short code on a shared kitchen screen, checked at the line\'s station', function () {
    $f = $this->floor;
    $screen = makeUser($f->branch, 'chef', ['name' => 'Kitchen screen']);
    $f->assign($screen, $f->menu->kitchenStation);
    $device = Device::factory()->create(['branch_id' => $f->branch->id, 'name' => 'Kitchen KDS']);
    $headers = fn ($session): array => ['X-Acting-Session' => (string) $session->id, 'X-Device-Id' => $device->fingerprint];

    // a waiter's code on the kitchen screen may not accept at all; a chef who is not assigned to the kitchen neither
    $waiterSession = app(ActingAsSession::class)->start($f->waiter, $device);
    $f->act('accept', $this->sandwich['id'], $screen, [], null, $headers($waiterSession))->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    $idleSession = app(ActingAsSession::class)->start($f->idleChef, $device);
    $f->act('accept', $this->sandwich['id'], $screen, [], null, $headers($idleSession))->assertStatus(403)->assertJsonPath('message_key', 'orders.station.forbidden');

    $session = app(ActingAsSession::class)->start($f->chef, $device);
    $f->act('accept', $this->sandwich['id'], $screen, [], null, $headers($session))->assertOk()
        ->assertJsonPath('accepted_by', $f->chef->id)
        ->assertJsonPath('prepared_by', $f->chef->id);

    $event = OrderEvent::query()->where('order_line_id', $this->sandwich['id'])->where('event', 'line.accepted')->firstOrFail();
    expect([$event->user_id, $event->acting_user_id, $event->device_id])->toBe([$screen->id, $f->chef->id, $device->id])
        ->and($event->payload['by_user_id'])->toBe($f->chef->id)
        ->and(DB::table('audit_log')->where('action', 'order_line.accept')->first(['actor_id', 'acting_user_id', 'device_id']))
        ->toEqual((object) ['actor_id' => $screen->id, 'acting_user_id' => $f->chef->id, 'device_id' => $device->id]);
})->group('module:orders', 'feature');

it('refuses a line already accepted, a rejected one, and a line of a cart that was never sent', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->act('accept', $this->sandwich['id'], $f->chef)->assertOk();

    $f->act('accept', $this->sandwich['id'], $f->chef)->assertStatus(409)
        ->assertJsonPath('message_key', 'state_machine.illegal_transition')
        ->assertJsonPath('from', 'accepted')
        ->assertJsonPath('to', 'accepted');
    $f->act('reject', $this->latte['id'], $f->barista, ['reason' => 'equipment_down'])->assertOk();
    $f->act('accept', $this->latte['id'], $f->barista)->assertStatus(409)->assertJsonPath('from', 'rejected');

    $cart = $f->send([$m->line($m->sandwich)], null, ['send' => false]);
    $f->act('accept', $cart['lines'][0]['id'], $f->chef)->assertStatus(409)
        ->assertJsonPath('message_key', 'orders.order.not_sent')
        ->assertJsonPath('status', 'open');
    expect(OrderLine::query()->findOrFail($cart['lines'][0]['id'])->status->value)->toBe('queued');
})->group('module:orders', 'feature');

it('answers 404 for a line of another branch', function () {
    $other = Branch::factory()->create();
    $theirs = OrderLine::factory()->create(['order_id' => Order::factory()->sent()->create(['branch_id' => $other->id, 'created_by_user_id' => makeUser($other, 'waiter')->id])->id]);

    $this->floor->act('accept', $theirs->id, $this->floor->admin)->assertStatus(404)->assertJsonPath('message_key', 'http.not_found');
    expect(OrderLine::query()->withoutGlobalScopes()->findOrFail($theirs->id)->status->value)->toBe('queued');
})->group('module:orders', 'feature');

it('replays the same Idempotency-Key with the same body and accepts once', function () {
    $f = $this->floor;
    $key = (string) Str::uuid();

    $first = $f->act('accept', $this->sandwich['id'], $f->chef, [], $key)->assertOk();
    $counts = [DB::table('audit_log')->count(), DB::table('order_events')->count()];
    $second = $f->act('accept', $this->sandwich['id'], $f->chef, [], $key);

    $second->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and([DB::table('audit_log')->count(), DB::table('order_events')->count()])->toBe($counts);
})->group('module:orders', 'feature');

it('rolls the accept back whole when one of its writes fails', function () {
    $f = $this->floor;
    $before = [DB::table('order_events')->count(), DB::table('audit_log')->count()];
    Event::listen('eloquent.creating: '.OrderEvent::class, function (): never {
        throw new RuntimeException('the disk is full');
    });
    $announced = [];
    Event::listen(LineAccepted::class, function (LineAccepted $e) use (&$announced): void {
        $announced[] = $e;
    });

    $f->act('accept', $this->sandwich['id'], $f->chef)->assertStatus(500);

    expect([DB::table('order_events')->count(), DB::table('audit_log')->count()])->toBe($before)
        ->and(OrderLine::query()->findOrFail($this->sandwich['id'])->status->value)->toBe('queued')
        ->and(Order::query()->findOrFail($this->order['id'])->status->value)->toBe('sent')
        ->and($announced)->toBe([], 'a rolled-back accept is never announced');
})->group('module:orders', 'feature');

it('dispatches LineAccepted once, after the commit, with its companion on the station and the owner, queueing nothing', function () {
    $f = $this->floor;
    $baseline = DB::connection()->transactionLevel();
    $seen = [];
    Event::listen(LineAccepted::class, function (LineAccepted $e) use (&$seen): void {
        $seen[] = [$e, DB::connection()->transactionLevel()];
    });
    $companions = [];
    Event::listen(OrderLineBroadcast::class, function (OrderLineBroadcast $b) use (&$companions): void {
        $companions[] = $b;
    });
    Queue::fake();

    $f->act('accept', $this->sandwich['id'], $f->chef)->assertOk();

    expect($seen)->toHaveCount(1)
        ->and($seen[0][1])->toBe($baseline, 'dispatched after the commit, outside the action\'s transaction')
        ->and($seen[0][0]->branchId)->toBe($f->branch->id)
        ->and($seen[0][0]->payload())->toBe([
            'line_id' => $this->sandwich['id'],
            'order_id' => $this->order['id'],
            'station_id' => $f->menu->kitchenStation->id,
            'by_user_id' => $f->chef->id,
            'owner_user_id' => $f->waiter->id,
        ])
        ->and($companions)->toHaveCount(1)
        ->and(array_map(fn ($c): string => $c->name, $companions[0]->broadcastOn()))->toBe(["private-station.{$f->menu->kitchenStation->id}", "private-user.{$f->waiter->id}"])
        ->and($companions[0]->broadcastAs())->toBe('line_accepted')
        ->and($companions[0]->broadcastWith()['payload']['line_id'])->toBe($this->sandwich['id'])
        ->and($companions[0]->broadcastWhen())->toBeFalse();
    Queue::assertNothingPushed();
})->group('module:orders', 'feature');
