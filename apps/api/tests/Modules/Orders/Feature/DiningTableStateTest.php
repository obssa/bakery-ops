<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Orders\Models\DiningTable;
use App\Shared\Events\PaymentRecorded;
use App\Shared\Models\Branch;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrderFloor;

/*
 * The derived `state` of GET /dining-tables (docs/phases/phase-1-plan.md section 4; step 1.5), computed from the table's
 * active orders in one grouped query:
 *   ordering  the table has an open (unsent) order;
 *   waiting   it has an order sent, in progress or ready;
 *   served    its only active orders are served and unpaid;
 *   free      otherwise (nothing, or only cancelled, voided, paid or closed orders).
 * With several active orders: waiting over ordering over served. `bill` is in the enum but Phase 1 never produces it
 * (there is no "request the bill" action yet).
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $this->table = fn (string $code): DiningTable => DiningTable::factory()->create(['branch_id' => $this->floor->branch->id, 'code' => $code, 'sort' => (int) substr($code, 1)]);
    $this->at = fn (DiningTable $table, array $lines, bool $send = true): array => $this->floor->send($lines, null, ['type' => 'dine_in', 'dining_table_id' => $table->id, 'send' => $send]);
    $this->states = function ($as = null): array {
        loginAs($as ?? $this->floor->waiter);
        $rows = $this->getJson('/api/v1/dining-tables')->assertOk()->json('data');

        return array_column($rows, 'state', 'code');
    };
    $this->serve = function (array $order): void {
        foreach ($order['lines'] as $line) {
            $this->floor->act('served', $line['id'], $this->floor->waiter)->assertOk();
        }
    };
});

it('derives free, ordering, waiting and served from the active orders of each table', function () {
    $f = $this->floor;
    $m = $f->menu;
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    $t = fn (string $code) => ($this->table)($code);
    [$t1, $t2, $t3, $t4, $t5, $t6, $t7, $t8, $t9] = array_map($t, ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9']);

    ($this->at)($t2, [$m->line($m->sandwich)], false); // ordering: an open cart
    ($this->at)($t3, [$m->line($m->sandwich)]); // waiting: sent
    $ready = ($this->at)($t4, [$m->line($m->croissant)]); // waiting: ready
    expect($ready['status'])->toBe('ready');
    $served = ($this->at)($t5, [$m->line($m->water)]); // served, unpaid
    ($this->serve)($served);
    ($this->at)($t6, [$m->line($m->sandwich)], false); // open + sent: waiting wins
    ($this->at)($t6, [$m->line($m->sandwich)]);
    ($this->at)($t7, [$m->line($m->sandwich)], false); // open + served: ordering wins over served
    ($this->serve)(($this->at)($t7, [$m->line($m->water)]));
    $cancelled = ($this->at)($t8, [$m->line($m->water)], false);
    loginAs($f->waiter);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$cancelled['id']}/cancel", [])->assertOk(); // cancelled: free
    $paid = ($this->at)($t9, [$m->line($m->water)]);
    ($this->serve)($paid);
    withActor($f->cashier->id);
    DB::transaction(fn () => event(new PaymentRecorded($f->branch->id, 1, $paid['id'], 'cash', $paid['totals']['total'], '0.00'))); // closed: free

    expect(($this->states)())->toBe([
        'T1' => 'free', 'T2' => 'ordering', 'T3' => 'waiting', 'T4' => 'waiting', 'T5' => 'served',
        'T6' => 'waiting', 'T7' => 'ordering', 'T8' => 'free', 'T9' => 'free',
    ]);
    // the grid is the same for every order taker
    expect(($this->states)($f->cashier))->toBe(($this->states)($f->waiter));
})->group('module:orders', 'feature');

it('moves a table through its life: free, ordering, waiting, served, free', function () {
    $f = $this->floor;
    $m = $f->menu;
    $table = ($this->table)('T1');
    expect(($this->states)())->toBe(['T1' => 'free']);

    $cart = ($this->at)($table, [$m->line($m->croissant)], false);
    expect(($this->states)())->toBe(['T1' => 'ordering']);

    loginAs($f->waiter);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$cart['id']}/send")->assertOk();
    expect(($this->states)())->toBe(['T1' => 'waiting']);

    ($this->serve)($cart);
    expect(($this->states)())->toBe(['T1' => 'served']);

    withActor($f->cashier->id);
    $total = Illuminate\Support\Facades\DB::table('orders')->where('id', $cart['id'])->value('total');
    DB::transaction(fn () => event(new PaymentRecorded($f->branch->id, 1, $cart['id'], 'cash', (string) $total, '0.00')));
    expect(($this->states)())->toBe(['T1' => 'free']);
})->group('module:orders', 'feature');

it('never produces bill in Phase 1', function () {
    $f = $this->floor;
    $m = $f->menu;
    $table = ($this->table)('T1');
    foreach ([false, true] as $send) {
        ($this->at)($table, [$m->line($m->croissant)], $send);
    }
    expect(array_unique(array_values(($this->states)())))->not->toContain('bill');
})->group('module:orders', 'feature');

it('counts only its own branch\'s orders and keeps an inactive table out of the grid', function () {
    $f = $this->floor;
    $m = $f->menu;
    $mine = ($this->table)('T1');
    $other = Branch::factory()->create();
    $theirTable = DiningTable::factory()->create(['branch_id' => $other->id, 'code' => 'T1', 'created_by_user_id' => makeUser($other, 'admin')->id]);
    App\Modules\Orders\Models\Order::factory()->sent(3)->atTable($theirTable)->create();
    $off = DiningTable::factory()->create(['branch_id' => $f->branch->id, 'code' => 'T2', 'is_active' => false]);
    ($this->at)($mine, [$m->line($m->croissant)], false);

    expect(($this->states)())->toBe(['T1' => 'ordering']);
    expect($off->is_active)->toBeFalse();
})->group('module:orders', 'feature');

it('takes the same few queries for one table as for thirty', function () {
    $f = $this->floor;
    $m = $f->menu;
    $count = function (): int {
        $n = 0;
        DB::listen(function (QueryExecuted $q) use (&$n): void {
            $n++;
        });
        ($this->states)();

        return $n;
    };
    $first = ($this->table)('T1');
    ($this->at)($first, [$m->line($m->croissant)], false);
    ($this->states)();
    $few = $count();
    for ($i = 2; $i <= 30; $i++) {
        $table = ($this->table)('T'.$i);
        if ($i % 3 === 0) {
            ($this->at)($table, [$m->line($m->croissant)], false);
        }
    }

    expect($count())->toBe($few);
})->group('module:orders', 'feature');

it('adds the state to the table a POST or a PATCH answers with', function () {
    $f = $this->floor;
    loginAs($f->admin);
    $created = $this->withHeaders(idempotencyKey())->postJson('/api/v1/dining-tables', ['code' => 'T1', 'name_en' => 'Table 1', 'name_am' => 'ጠረጴዛ 1']);
    $created->assertCreated()->assertJsonPath('state', 'free');
    $this->withHeaders(idempotencyKey())->patchJson('/api/v1/dining-tables/'.$created->json('id'), ['seats' => 6])->assertOk()->assertJsonPath('state', 'free');
})->group('module:orders', 'feature');
