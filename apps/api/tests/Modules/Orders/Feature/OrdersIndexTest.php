<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\Device;
use App\Modules\Orders\Enums\OrderEventType;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

/*
 * GET /orders (docs/phases/phase-1-plan.md section 4, section 10; ADR 0009; step 1.5): the pad's and the till's order
 * list. A waiter sees the orders they own (owner = the waiter acting by short code, else the creator); the cashier, the
 * manager and the admin see the branch (?mine=1 narrows to their own). Filters: unpaid (sent through served), status
 * (active by default), dining_table_id; ?since= is a poll over the branch's order_events through OverlapCursor with the
 * same overlap window and ETag / 304 rules as the station rail.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->bekele = makeUser($this->floor->branch, 'waiter', ['name' => 'Bekele']);
    $this->list = function (array $query = [], $as = null, array $headers = []) {
        loginAs($as ?? $this->floor->cashier);

        return $this->withHeaders($headers)->getJson('/api/v1/orders'.($query === [] ? '' : '?'.http_build_query($query)));
    };
    $this->ids = fn ($response): array => array_column((array) $response->json('data'), 'id');
    $this->settle = fn () => $this->travelTo(CarbonImmutable::now()->addSeconds(10));
    $this->pay = function (int $orderId, string $amount): void {
        withActor($this->floor->cashier->id);
        DB::transaction(function () use ($orderId, $amount): void {
            event(new PaymentRecorded($this->floor->branch->id, 1, $orderId, 'cash', $amount, '0.00'));
        });
    };
    $this->cart = function ($as, array $lines, array $extra = []) {
        loginAs($as);
        $response = $this->withHeaders(idempotencyKey())->postJson('/api/v1/orders', $this->floor->menu->order($lines, $extra + ['send' => false]));
        $response->assertCreated();

        return $response->json();
    };
});

it('answers {data, cursor, server_time} with the Order resource, newest first, without the events', function () {
    $f = $this->floor;
    $m = $f->menu;
    $a = $f->send([$m->line($m->sandwich)]);
    $b = $f->send([$m->line($m->croissant)]);

    $response = ($this->list)();
    $response->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    expect(array_keys($response->json()))->toBe(['data', 'cursor', 'server_time'])
        ->and(($this->ids)($response))->toBe([$b['id'], $a['id']])
        ->and($response->json('data.1'))->toBe($a)
        ->and($response->json('data.1'))->not->toHaveKey('events')
        ->and($response->json('cursor'))->toBeString()
        ->and($response->headers->get('ETag'))->toBeString();
})->group('module:orders', 'feature');

it('shows a waiter only the orders they own, whatever ?mine says, and the till every order of the branch', function () {
    $f = $this->floor;
    $m = $f->menu;
    $mine = $f->send([$m->line($m->sandwich)]);
    $theirs = $f->send([$m->line($m->latte)], $this->bekele);
    $till = $f->send([$m->line($m->croissant)], $f->cashier);

    foreach ([[], ['mine' => 0], ['mine' => 1]] as $query) {
        expect(($this->ids)(($this->list)($query, $f->waiter)))->toBe([$mine['id']]);
    }
    expect(($this->ids)(($this->list)([], $this->bekele)))->toBe([$theirs['id']]);

    foreach ([$f->cashier, $f->manager, $f->admin] as $who) {
        expect(($this->ids)(($this->list)([], $who)))->toBe([$till['id'], $theirs['id'], $mine['id']]);
    }
    expect(($this->ids)(($this->list)(['mine' => 1], $f->cashier)))->toBe([$till['id']])
        ->and(($this->ids)(($this->list)(['mine' => 1], $f->manager)))->toBe([]);
})->group('module:orders', 'feature');

it('takes whose orders they are from the waiter acting by short code on a shared pad', function () {
    $f = $this->floor;
    $m = $f->menu;
    $pad = makeUser($f->branch, 'waiter', ['name' => 'Shared pad']);
    $device = Device::factory()->create(['branch_id' => $f->branch->id, 'name' => 'Pad 1']);
    $headers = ['X-Acting-Session' => (string) app(ActingAsSession::class)->start($f->waiter, $device)->id, 'X-Device-Id' => $device->fingerprint];

    loginAs($pad);
    $created = $this->withHeaders(idempotencyKey() + $headers)->postJson('/api/v1/orders', $m->order([$m->line($m->croissant)]));
    $created->assertCreated()->assertJsonPath('owner.id', $f->waiter->id);
    $other = $f->send([$m->line($m->water)], $this->bekele);

    expect(($this->ids)(($this->list)([], $pad, $headers)))->toBe([$created->json('id')])
        ->and(($this->ids)(($this->list)([], $pad)))->toBe([]); // the pad account on its own owns nothing
    expect(($this->ids)(($this->list)([], $f->waiter)))->toBe([$created->json('id')])->and($other['id'])->not->toBe($created->json('id'));
})->group('module:orders', 'feature');

it('lists the active orders by default and filters by status', function () {
    $f = $this->floor;
    $m = $f->menu;
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    $open = ($this->cart)($f->waiter, [$m->line($m->sandwich)]);
    $sent = $f->send([$m->line($m->sandwich)]);
    $ready = $f->send([$m->line($m->croissant)]);
    $cancelled = ($this->cart)($f->waiter, [$m->line($m->water)]);
    loginAs($f->waiter);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$cancelled['id']}/cancel", ['reason' => 'Changed mind'])->assertOk();
    $voided = $f->send([$m->line($m->latte)]);
    loginAs($f->cashier);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$voided['id']}/void", ['reason' => 'Left', 'pin' => '246802'])->assertOk();
    $closed = $f->send([$m->line($m->water)]);
    $f->act('served', $f->lineOf($closed, $m->water)['id'], $f->waiter)->assertOk();
    ($this->pay)($closed['id'], $closed['totals']['total']);

    expect(($this->ids)(($this->list)()))->toBe([$ready['id'], $sent['id'], $open['id']])
        ->and(($this->ids)(($this->list)(['status' => 'active'])))->toBe([$ready['id'], $sent['id'], $open['id']]);
    foreach ([['open', [$open]], ['sent', [$sent]], ['ready', [$ready]], ['cancelled', [$cancelled]], ['voided', [$voided]], ['closed', [$closed]], ['served', []]] as [$status, $orders]) {
        expect(($this->ids)(($this->list)(['status' => $status])))->toBe(array_column($orders, 'id'), "status {$status}");
    }
    ($this->list)(['status' => 'nonsense'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
})->group('module:orders', 'feature');

it('filters ?unpaid=1 to orders sent through served: not open, not settled, not cancelled or voided', function () {
    $f = $this->floor;
    $m = $f->menu;
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    ($this->cart)($f->waiter, [$m->line($m->sandwich)]); // open
    $sent = $f->send([$m->line($m->sandwich)]);
    $inProgress = $f->send([$m->line($m->sandwich)]);
    $f->act('accept', $f->lineOf($inProgress, $m->sandwich)['id'], $f->chef)->assertOk();
    $ready = $f->send([$m->line($m->croissant)]);
    $served = $f->send([$m->line($m->water)]);
    $f->act('served', $f->lineOf($served, $m->water)['id'], $f->waiter)->assertOk();
    $paid = $f->send([$m->line($m->water)]);
    $f->act('served', $f->lineOf($paid, $m->water)['id'], $f->waiter)->assertOk();
    ($this->pay)($paid['id'], $paid['totals']['total']);
    $voided = $f->send([$m->line($m->latte)]);
    loginAs($f->cashier);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$voided['id']}/void", ['reason' => 'Left', 'pin' => '246802'])->assertOk();

    expect(($this->ids)(($this->list)(['unpaid' => 1])))->toBe([$served['id'], $ready['id'], $inProgress['id'], $sent['id']]);
    expect(($this->ids)(($this->list)(['unpaid' => 1], $f->waiter)))->toBe([$served['id'], $ready['id'], $inProgress['id'], $sent['id']]);
    expect(($this->ids)(($this->list)(['unpaid' => 0])))->toContain($sent['id'])->not->toContain($paid['id']);
})->group('module:orders', 'feature');

it('filters by dining_table_id', function () {
    $f = $this->floor;
    $m = $f->menu;
    $t1 = DiningTable::factory()->create(['branch_id' => $f->branch->id, 'code' => 'T1']);
    $t2 = DiningTable::factory()->create(['branch_id' => $f->branch->id, 'code' => 'T2']);
    $one = $f->send([$m->line($m->water)], null, ['type' => 'dine_in', 'dining_table_id' => $t1->id]);
    $two = $f->send([$m->line($m->water)], null, ['type' => 'dine_in', 'dining_table_id' => $t2->id]);
    $take = $f->send([$m->line($m->water)]);

    expect(($this->ids)(($this->list)(['dining_table_id' => $t1->id])))->toBe([$one['id']])
        ->and(($this->ids)(($this->list)(['dining_table_id' => $t2->id], $f->waiter)))->toBe([$two['id']])
        ->and(($this->ids)(($this->list)()))->toBe([$take['id'], $two['id'], $one['id']]);
    ($this->list)(['dining_table_id' => 'x'])->assertStatus(422);
})->group('module:orders', 'feature');

it('polls with ?since= : only the orders with an event after the cursor, and the cursor never goes back', function () {
    $f = $this->floor;
    $m = $f->menu;
    $a = $f->send([$m->line($m->sandwich)]);
    $b = $f->send([$m->line($m->sandwich)]);
    ($this->settle)();

    $first = ($this->list)();
    $cursor = $first->json('cursor');
    expect(($this->ids)($first))->toBe([$b['id'], $a['id']])->and((int) $cursor)->toBeGreaterThan(0);

    $quiet = ($this->list)(['since' => $cursor]);
    expect($quiet->json('data'))->toBe([])->and($quiet->json('cursor'))->toBe($cursor);

    $f->act('accept', $f->lineOf($a, $m->sandwich)['id'], $f->chef)->assertOk();
    $poll = ($this->list)(['since' => $cursor]);
    expect(($this->ids)($poll))->toBe([$a['id']])
        ->and($poll->json('data.0.status'))->toBe('in_progress')
        ->and($poll->json('cursor'))->toBe($cursor); // the new events are still inside the overlap window

    ($this->settle)();
    $later = ($this->list)(['since' => $cursor]);
    expect(($this->ids)($later))->toBe([$a['id']])->and((int) $later->json('cursor'))->toBeGreaterThan((int) $cursor)
        ->and(($this->list)(['since' => $later->json('cursor')])->json('data'))->toBe([]);
    expect(($this->list)(['since' => '999999'])->json('cursor'))->toBe('999999');
    // a cursor that is not a number is a first load
    expect(($this->ids)(($this->list)(['since' => 'abc'])))->toBe([$b['id'], $a['id']]);
})->group('module:orders', 'feature');

it('still delivers an order whose event commits late, below an id already served', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->send([$m->line($m->sandwich)]);
    ($this->settle)();
    $cursor = ($this->list)()->json('cursor');
    $top = (int) OrderEvent::query()->max('id');
    $early = Order::factory()->sent(41)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->waiter->id]);
    $late = Order::factory()->sent(42)->create(['branch_id' => $f->branch->id, 'created_by_user_id' => $f->waiter->id]);
    $event = fn (Order $o, int $id) => OrderEvent::factory()->create(['id' => $id, 'order_id' => $o->id, 'owner_user_id' => $o->owner_user_id, 'event' => OrderEventType::OrderSent, 'created_at' => CarbonImmutable::now()]);

    $event($early, $top + 10);
    $seen = ($this->list)(['since' => $cursor]);
    expect(($this->ids)($seen))->toBe([$early->id]);

    $event($late, $top + 5);
    $again = ($this->list)(['since' => $cursor], null, ['If-None-Match' => $seen->headers->get('ETag')]);
    expect($again->status())->toBe(200)->and(($this->ids)($again))->toBe([$late->id, $early->id]);
})->group('module:orders', 'feature');

it('answers 304 from the feed fingerprint without building the page, and the tag follows the scope', function () {
    $f = $this->floor;
    $m = $f->menu;
    $f->send([$m->line($m->sandwich)]);
    $f->send([$m->line($m->latte)], $this->bekele);
    ($this->settle)();
    $etag = ($this->list)()->headers->get('ETag');

    $sql = [];
    DB::listen(function (QueryExecuted $q) use (&$sql): void {
        $sql[] = $q->sql;
    });
    $again = ($this->list)([], null, ['If-None-Match' => $etag]);
    $again->assertStatus(304);
    expect($again->getContent())->toBe('')->and($again->headers->get('ETag'))->toBe($etag)->and($again->headers->get('Cache-Control'))->toBe('no-cache, private');
    foreach ($sql as $statement) {
        expect($statement)->not->toContain('`order_lines`')->and($statement)->not->toContain('from `orders`');
    }
    expect(($this->list)([], null, ['If-None-Match' => 'W/'.$etag])->status())->toBe(304);

    // the same tag is not valid for another filter, another cursor or another person's list
    expect(($this->list)(['unpaid' => 1], null, ['If-None-Match' => $etag])->status())->toBe(200)
        ->and(($this->list)(['since' => '1'], null, ['If-None-Match' => $etag])->status())->toBe(200)
        ->and(($this->list)([], $f->waiter, ['If-None-Match' => $etag])->status())->toBe(200);

    // a waiter's tag does not move when another waiter's order changes, and the till's does
    $mineTag = ($this->list)([], $f->waiter)->headers->get('ETag');
    $f->send([$m->line($m->water)], $this->bekele);
    ($this->settle)();
    expect(($this->list)([], $f->waiter, ['If-None-Match' => $mineTag])->status())->toBe(304)
        ->and(($this->list)([], null, ['If-None-Match' => $etag])->status())->toBe(200);
})->group('module:orders', 'feature');

it('never shows another branch\'s orders or events', function () {
    $f = $this->floor;
    $m = $f->menu;
    $other = Branch::factory()->create();
    $theirs = Order::factory()->sent(5)->create(['branch_id' => $other->id]);
    OrderEvent::factory()->create(['order_id' => $theirs->id, 'owner_user_id' => $theirs->owner_user_id, 'event' => OrderEventType::OrderSent]);
    $mine = $f->send([$m->line($m->water)]);
    ($this->settle)();

    $response = ($this->list)();
    expect(($this->ids)($response))->toBe([$mine['id']]);
    expect(($this->ids)(($this->list)(['since' => '0'])))->toBe([$mine['id']]);
    $otherTag = ($this->list)(['since' => '0'])->headers->get('ETag');
    OrderEvent::factory()->create(['order_id' => $theirs->id, 'owner_user_id' => $theirs->owner_user_id, 'event' => OrderEventType::OrderPaid]);
    ($this->settle)();
    expect(($this->list)(['since' => '0'], null, ['If-None-Match' => $otherTag])->status())->toBe(304);
})->group('module:orders', 'feature');

it('is for the order takers: orders.create', function () {
    $f = $this->floor;
    foreach ([$f->chef, $f->barista, $f->cakeBaker, $f->breadBaker] as $user) {
        ($this->list)([], $user)->assertForbidden();
    }
    foreach ([$f->admin, $f->manager, $f->cashier, $f->waiter] as $user) {
        ($this->list)([], $user)->assertOk();
    }
})->group('module:orders', 'feature');

it('takes a fixed number of queries however many orders it lists', function () {
    $f = $this->floor;
    $m = $f->menu;
    $count = function () {
        $n = 0;
        DB::listen(function (QueryExecuted $q) use (&$n): void {
            $n++;
        });
        ($this->list)()->assertOk();

        return $n;
    };
    $f->send([$m->line($m->sandwich, '1', [$m->noOnion]), $m->line($m->croissant)]);
    ($this->list)();
    $few = $count();
    for ($i = 0; $i < 6; $i++) {
        $f->send([$m->line($m->sandwich, '1', [$m->noOnion, $m->withTomato]), $m->line($m->croissant), $m->line($m->water)], null, ['notes' => Str::random(5)]);
    }

    expect($count())->toBe($few);
})->group('module:orders', 'feature');
