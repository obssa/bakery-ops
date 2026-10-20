<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Shared\Models\Branch;
use Tests\Support\OrderFloor;

/*
 * GET /orders/{order} (docs/phases/phase-1-plan.md section 4; step 1.5): the Order resource with its `events`
 * timeline, oldest first. The owner and the people who see every order (cashier, manager, admin) may read it; anyone
 * else, another branch's order and a waiter's neighbour's order are all 404 (the order is not theirs to know about).
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->bekele = makeUser($this->floor->branch, 'waiter', ['name' => 'Bekele']);
    $this->show = function (int $id, $as) {
        loginAs($as);

        return $this->getJson("/api/v1/orders/{$id}");
    };
});

it('returns the order with its lines and its event timeline, oldest first', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->sandwich, '2', [$m->noOnion]), $m->line($m->croissant)]);
    $f->act('accept', $f->lineOf($order, $m->sandwich)['id'], $f->chef)->assertOk();

    $response = ($this->show)($order['id'], $f->waiter);

    $response->assertOk();
    $body = $response->json();
    $events = $body['events'];
    unset($body['events']);
    $listed = $response->json();
    expect(array_keys($listed))->toContain('events', 'lines', 'totals', 'owner')
        ->and($body['id'])->toBe($order['id'])->and($body['lines'])->toHaveCount(2)
        ->and($body['status'])->toBe('in_progress')
        ->and(array_column($events, 'id'))->toBe(OrderEvent::query()->where('order_id', $order['id'])->orderBy('id')->pluck('id')->all())
        ->and(array_column($events, 'event'))->toContain('order.created', 'order.sent', 'line.queued', 'line.accepted');
    expect($events[0])->toHaveKeys(['id', 'event', 'order_line_id', 'station_id', 'user_id', 'acting_user_id', 'approved_by_user_id', 'payload', 'at'])
        ->and($events[0]['event'])->toBe('order.created');
    $accepted = collect($events)->firstWhere('event', 'line.accepted');
    expect($accepted['user_id'])->toBe($f->chef->id)->and($accepted['order_line_id'])->toBe($f->lineOf($order, $m->sandwich)['id'])
        ->and($accepted['station_id'])->toBe($m->kitchenStation->id)->and($accepted['at'])->toBeString();
})->group('module:orders', 'feature');

it('lets the owner, the cashier, the manager and the admin read it', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->water)]);

    foreach ([$f->waiter, $f->cashier, $f->manager, $f->admin] as $who) {
        ($this->show)($order['id'], $who)->assertOk()->assertJsonPath('id', $order['id']);
    }
})->group('module:orders', 'feature');

it('is 404 for a waiter who does not own it, and for an order of another branch or one that does not exist', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->water)]);
    $other = Branch::factory()->create();

    ($this->show)($order['id'], $this->bekele)->assertNotFound();
    ($this->show)(999999, $f->cashier)->assertNotFound();

    $foreign = Order::factory()->sent(9)->create(['branch_id' => $other->id]);
    ($this->show)($foreign->id, $f->admin)->assertNotFound();
    ($this->show)($foreign->id, $f->cashier)->assertNotFound();
})->group('module:orders', 'feature');

it('is for the order takers: orders.create', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = $f->send([$m->line($m->water)]);
    foreach ([$f->chef, $f->barista, $f->cakeBaker, $f->breadBaker] as $user) {
        ($this->show)($order['id'], $user)->assertForbidden();
    }
})->group('module:orders', 'feature');
