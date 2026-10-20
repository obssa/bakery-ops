<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\OrdersMenu;

/*
 * Rule 3 under real concurrency (docs/phases/phase-1-plan.md section 7, Concurrency; ADR 0008): two waiters send the
 * last cake at the same moment from two pads. Each POST /orders/{order}/send runs in its own process on its own
 * database connection (Forks::together), through the whole HTTP stack, so SendOrder's one transaction, the balance row
 * lock taken by Reservations::reserveMany and the order_counters row lock really race. Exactly one order is sent and
 * numbered; the other gets the 409 shortfall and stays open; the display case never shows less than zero. The same
 * holds for the daily number (two sends at once take 1 and 2) and for one request sent twice with one
 * Idempotency-Key (one order). CommitsForConcurrency (DatabaseTruncation): the children must see committed rows.
 */

uses(CommitsForConcurrency::class);

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->menu = OrdersMenu::build($this->branch);
    $this->waiters = [makeUser($this->branch, 'waiter'), makeUser($this->branch, 'waiter')];

    // one open cart per waiter
    $this->cart = function (int $waiter, array $lines): int {
        loginAs($this->waiters[$waiter]);

        return (int) $this->withHeaders(idempotencyKey())->postJson('/api/v1/orders', $this->menu->order($lines, ['send' => false]))->assertCreated()->json('id');
    };
    // a job for Forks::together: waiter $i sends order $orderId and reports what came back
    $this->sendJob = fn (int $waiter, int $orderId): Closure => function () use ($waiter, $orderId): array {
        loginAs($this->waiters[$waiter]);
        $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$orderId}/send");

        return [
            'status' => $response->getStatusCode(),
            'message_key' => $response->json('message_key'),
            'order_status' => $response->json('status') ?? $response->json('order.status'),
            'order_no' => $response->json('order_no'),
            'short' => $response->json('shortfall.0.qty_short_display'),
        ];
    };
});

it('sells the last cake once when two waiters send it at the same moment on two connections', function () {
    $m = $this->menu;
    $first = ($this->cart)(0, [$m->line($m->cake)]);
    $second = ($this->cart)(1, [$m->line($m->cake)]);

    $results = Forks::together([($this->sendJob)(0, $first), ($this->sendJob)(1, $second)]);

    foreach ($results as $i => $result) {
        expect($result['ok'] ?? false)->toBeTrue("send {$i}: ".($result['error'] ?? ''));
    }
    $byStatus = collect($results)->pluck('value')->keyBy('status');
    expect($byStatus->keys()->sort()->values()->all())->toBe([200, 409], 'exactly one send wins')
        ->and($byStatus[200])->toMatchArray(['order_status' => 'ready', 'order_no' => 1])
        ->and($byStatus[409])->toMatchArray(['message_key' => 'orders.send.shortfall', 'order_status' => 'open', 'short' => '1 pcs']);

    expect(stockRow($m->cake, $m->display))->on_hand->toBe('1.0000')->reserved->toBe('1.0000')
        ->and(DB::table('stock_balances')->whereRaw('on_hand - reserved < 0')->count())->toBe(0, 'available never below zero')
        ->and(DB::table('stock_movements')->where('item_id', $m->cake->id)->where('reason', 'reservation')->count())->toBe(1)
        ->and(Order::query()->whereKey([$first, $second])->orderBy('id')->get()->map(fn (Order $o): array => [$o->status->value, $o->order_no])->sort()->values()->all())
        ->toBe([['open', null], ['ready', 1]]);
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['movements'])->toBe($oracle['balances'])->and($oracle['balances'])->toBe($oracle['view']);
})->group('concurrency', 'module:orders', 'feature');

it('gives two orders sent at the same moment the numbers 1 and 2 of the day, never the same one', function () {
    $m = $this->menu;
    $first = ($this->cart)(0, [$m->line($m->water)]);
    $second = ($this->cart)(1, [$m->line($m->croissant)]);

    $results = Forks::together([($this->sendJob)(0, $first), ($this->sendJob)(1, $second)]);

    foreach ($results as $i => $result) {
        expect($result['ok'] ?? false)->toBeTrue("send {$i}: ".($result['error'] ?? ''))
            ->and($result['value']['status'] ?? null)->toBe(200);
    }
    expect(collect($results)->pluck('value.order_no')->sort()->values()->all())->toBe([1, 2])
        ->and((int) DB::table('order_counters')->where('branch_id', $this->branch->id)->value('last_no'))->toBe(2);
})->group('concurrency', 'module:orders', 'feature');

it('creates one order when the same POST /orders arrives twice at once under one Idempotency-Key', function () {
    config(['cache.default' => 'database']); // the key lock must be shared between the two processes, as Redis is live
    $m = $this->menu;
    loginAs($this->waiters[0]);
    $key = idempotencyKey();
    $body = $m->order([$m->line($m->water)]);
    $post = fn (): Closure => function () use ($key, $body): array {
        $response = $this->withHeaders($key)->postJson('/api/v1/orders', $body);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key'), 'id' => $response->json('id'), 'replayed' => $response->headers->get('Idempotency-Replayed')];
    };

    $results = Forks::together([$post(), $post()]);

    foreach ($results as $i => $result) {
        expect($result['ok'] ?? false)->toBeTrue("post {$i}: ".($result['error'] ?? ''));
    }
    $values = collect($results)->pluck('value');
    $created = $values->where('status', 201);
    expect(Order::query()->where('client_uuid', $body['client_uuid'])->count())->toBe(1)
        ->and($created)->not->toBeEmpty();
    foreach ($values as $value) {
        $inFlight = $value['status'] === 409 && $value['message_key'] === 'idempotency.in_flight';
        $sameOrder = $value['status'] === 201 && $value['id'] === $created->first()['id'];
        expect($inFlight || $sameOrder)->toBeTrue('the second one waits (409 idempotency.in_flight) or replays: '.json_encode($value));
    }
    expect(DB::table('stock_movements')->where('item_id', $m->water->id)->where('reason', 'reservation')->count())->toBe(1);
})->group('concurrency', 'module:orders', 'feature');
