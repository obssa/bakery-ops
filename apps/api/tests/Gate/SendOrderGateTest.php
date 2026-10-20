<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

/*
 * Phase 1 gate: SendOrder is one transaction (rule 3, ADR 0008) and a send is idempotent (rule 7, ADR 0003): the same
 * Idempotency-Key and body twice return the identical body and write one order. The detail (price snapshot, shortfall,
 * rollback, events) is tests/Modules/Orders/Feature/SendOrderTest.php; this is the gate's own check.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
});

it('writes the order, its numbering, its reservations and its audit row inside one transaction', function () {
    $f = $this->floor;
    $m = $f->menu;
    loginAs($f->waiter);
    $cart = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/v1/orders', $m->order([$m->line($m->sandwich), $m->line($m->latte), $m->line($m->croissant)], ['send' => false]))
        ->assertCreated()->assertJsonPath('status', 'open');

    // RefreshDatabase wraps the test in a transaction: the send's own BEGIN is the next level up
    $baseline = DB::connection()->transactionLevel();
    $transactions = 0;
    $writes = [];
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $e) use (&$transactions, $baseline): void {
        if ($e->connection->transactionLevel() === $baseline + 1) {
            $transactions++;
        }
    });
    DB::listen(function (QueryExecuted $q) use (&$writes, &$transactions, $baseline): void {
        if (preg_match('/^\s*(?:insert\s+(?:ignore\s+)?into|update|delete\s+from)\s+[`"]?(\w+)/i', $q->sql, $match) === 1 && $match[1] !== 'idempotency_keys') {
            $writes[] = [$match[1], $q->connection->transactionLevel() > $baseline ? $transactions : null];
        }
    });

    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$cart->json('id')}/send")->assertOk()->assertJsonPath('status', 'sent');

    $tables = collect($writes)->pluck(0)->unique()->sort()->values()->all();
    expect($tables)->toContain('orders', 'order_lines', 'order_counters', 'order_events', 'stock_movements', 'stock_balances', 'audit_log')
        ->and(collect($writes)->pluck(1)->contains(null))->toBeFalse('no write of the send runs outside a transaction')
        ->and(collect($writes)->pluck(1)->unique()->values()->all())->toHaveCount(1, 'one BEGIN covers every write, savepoints included');
})->group('gate:p1', 'feature', 'module:orders');

it('answers the same Idempotency-Key and body with the identical body and one order, and the same client UUID under a new key with the same order', function () {
    $f = $this->floor;
    $m = $f->menu;
    loginAs($f->waiter);
    $body = $m->order([$m->line($m->sandwich), $m->line($m->croissant)]);
    $key = (string) Str::uuid();

    $first = $this->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/orders', $body)->assertCreated();
    $movements = DB::table('stock_movements')->count();
    $second = $this->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/orders', $body);

    $second->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and(Order::query()->where('client_uuid', $body['client_uuid'])->count())->toBe(1)
        ->and(DB::table('stock_movements')->count())->toBe($movements);

    $again = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/orders', $body)->assertOk();
    expect($again->json('id'))->toBe($first->json('id'))
        ->and(Order::query()->count())->toBe(1)
        ->and(DB::table('stock_movements')->count())->toBe($movements);
})->group('gate:p1', 'feature', 'module:orders');
