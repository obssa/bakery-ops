<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActionsMatrix;
use Tests\Support\OrderFloor;

pest()->group('module:orders', 'feature');

/*
 * The `actions` of an order and of its lines (ADR 0032): for every state an order (and a line) can be in and every role of the bakery,
 * the list the API gives that person (GET /orders/{id}, and the station's tickets for the kitchen and the bar) is exactly the set of
 * moves the endpoints accept from that person now and that change the order. The pad, the till, the displays and the order record read
 * the list instead of a copy of the rules.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));
    $this->floor = OrderFloor::open($this);
    $f = $this->floor;
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    $this->otherWaiter = makeUser($f->branch, 'waiter', ['name' => 'Hana']);
    stockIn($f->menu->water, $f->menu->display, '50');
    $m = $f->menu;
    $this->at = function (string $when = '2026-11-02 10:00:00'): void {
        $this->travelTo(CarbonImmutable::parse($when, 'UTC'));
    };
    $this->post = fn (string $uri, array $body = []): TestResponse => $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/{$uri}", $body);
    $this->open = function (?User $by = null) use ($f, $m): Order {
        loginAs($by ?? $f->waiter);
        $id = $this->withHeaders(idempotencyKey())->postJson('/api/v1/orders', $m->order([$m->line($m->sandwich), $m->line($m->water)], ['send' => false]))->assertCreated()->json('id');

        return Order::query()->findOrFail($id);
    };
    $this->sent = fn (): array => $f->send([$m->line($m->sandwich), $m->line($m->water)]);
    $this->pay = function (array $order): void {
        loginAs($this->floor->cashier);
        $total = Order::query()->findOrFail($order['id'])->total->amount();
        ($this->post)("orders/{$order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => $total])->assertCreated();
    };
    $this->serveAll = function (array $order) use ($f): void {
        foreach ($order['lines'] as $line) {
            if (($line['station']['code'] ?? null) === 'kitchen') {
                $f->act('ready', $line['id'], $f->chef)->assertOk();
            }
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }
    };
    $this->people = [
        'admin' => $f->admin, 'manager' => $f->manager, 'cashier' => $f->cashier, 'waiter' => $f->waiter, 'other waiter' => $this->otherWaiter,
        'chef' => $f->chef, 'barista' => $f->barista, 'idle chef' => $f->idleChef, 'cake baker' => $f->cakeBaker,
    ];
});

it('lists exactly the order moves the endpoints accept, in every state and for every role', function () {
    $f = $this->floor;
    $m = $f->menu;
    $order = fn (array $o): Order => Order::query()->findOrFail($o['id']);
    $records = [
        'open' => function (): Order {
            ($this->at)();

            return ($this->open)();
        },
        'open, of another waiter' => function (): Order {
            ($this->at)();

            return ($this->open)($this->otherWaiter);
        },
        'sent' => function () use ($order): Order {
            ($this->at)();

            return $order(($this->sent)());
        },
        'in progress' => function () use ($f, $order): Order {
            ($this->at)();
            $o = ($this->sent)();
            $f->act('accept', $f->lineOf($o, $f->menu->sandwich)['id'], $f->chef)->assertOk();

            return $order($o);
        },
        'served' => function () use ($order): Order {
            ($this->at)();
            $o = ($this->sent)();
            ($this->serveAll)($o);

            return $order($o);
        },
        'paid, not served yet' => function () use ($order): Order {
            ($this->at)();
            $o = ($this->sent)();
            ($this->pay)($o);

            return $order($o);
        },
        'closed' => function () use ($order): Order {
            ($this->at)();
            $o = ($this->sent)();
            ($this->serveAll)($o);
            ($this->pay)($o);

            return $order($o);
        },
        'closed four days ago' => function () use ($order): Order {
            ($this->at)();
            $o = ($this->sent)();
            ($this->serveAll)($o);
            ($this->pay)($o);
            ($this->at)('2026-11-06 11:00:00');

            return $order($o);
        },
        'cancelled' => function (): Order {
            ($this->at)();
            $o = ($this->open)();
            ($this->post)("orders/{$o->id}/cancel", ['reason' => 'Left'])->assertOk();

            return $o->fresh();
        },
        'voided' => function () use ($f, $order): Order {
            ($this->at)();
            $o = ($this->sent)();
            loginAs($f->cashier);
            ($this->post)("orders/{$o['id']}/void", ['reason' => 'Left', 'pin' => '246802'])->assertOk();

            return $order($o);
        },
    ];
    $calls = [
        'edit' => fn (Order $o) => $this->withHeaders(idempotencyKey())->putJson("/api/v1/orders/{$o->id}/lines", ['lines' => [$m->line($m->water, '2')]]),
        'send' => fn (Order $o) => ($this->post)("orders/{$o->id}/send"),
        'cancel' => fn (Order $o) => ($this->post)("orders/{$o->id}/cancel", ['reason' => 'Left']),
        'void' => fn (Order $o) => ($this->post)("orders/{$o->id}/void", ['reason' => 'Left', 'pin' => '246802']),
        'refund' => fn (Order $o) => ($this->post)("orders/{$o->id}/refund", ['client_uuid' => (string) Str::uuid(), 'reason' => 'Brought back', 'pin' => '246802']),
    ];
    $listed = function (Order $o): array {
        $response = $this->getJson("/api/v1/orders/{$o->id}");

        return $response->status() === 200 ? $response->json('actions') : [];
    };
    $fingerprint = fn (Order $o): array => [
        DB::table('orders')->where('id', $o->id)->first(['status', 'version', 'refunded_total']),
        DB::table('order_lines')->where('order_id', $o->id)->orderBy('id')->get(['status', 'version'])->all(),
    ];

    expect(ActionsMatrix::mismatches($records, $this->people, $calls, $listed, $fingerprint))->toBe([]);
});

it('lists exactly the line steps the endpoints accept, in every state and for every role', function () {
    $f = $this->floor;
    $line = fn (array $o, $item): OrderLine => OrderLine::query()->findOrFail($f->lineOf($o, $item)['id']);
    $sandwich = function (string ...$steps) use ($f, $line): OrderLine {
        ($this->at)();
        $o = ($this->sent)();
        $id = $f->lineOf($o, $f->menu->sandwich)['id'];
        foreach ($steps as $step) {
            $f->act($step, $id, $step === 'served' ? $f->waiter : $f->chef, $step === 'reject' ? ['reason' => 'out_of_stock'] : [])->assertOk();
        }

        return $line($o, $f->menu->sandwich);
    };
    $records = [
        'queued at the kitchen' => fn (): OrderLine => $sandwich(),
        'accepted' => fn (): OrderLine => $sandwich('accept'),
        'ready' => fn (): OrderLine => $sandwich('accept', 'ready'),
        'served' => fn (): OrderLine => $sandwich('accept', 'ready', 'served'),
        'rejected' => fn (): OrderLine => $sandwich('reject'),
        'of an order not sent' => function () use ($f): OrderLine {
            ($this->at)();

            return OrderLine::query()->where('order_id', ($this->open)()->id)->where('item_id', $f->menu->sandwich->id)->firstOrFail();
        },
        'of a voided order' => function () use ($f, $line): OrderLine {
            ($this->at)();
            $o = ($this->sent)();
            loginAs($f->cashier);
            ($this->post)("orders/{$o['id']}/void", ['reason' => 'Left', 'pin' => '246802'])->assertOk();

            return $line($o, $f->menu->sandwich);
        },
        'from the display case, ready at the send' => function () use ($line): OrderLine {
            ($this->at)();

            return $line(($this->sent)(), $this->floor->menu->water);
        },
    ];
    $calls = [
        'accept' => fn (OrderLine $l) => ($this->post)("order-lines/{$l->id}/accept"),
        'ready' => fn (OrderLine $l) => ($this->post)("order-lines/{$l->id}/ready"),
        'reject' => fn (OrderLine $l) => ($this->post)("order-lines/{$l->id}/reject", ['reason' => 'out_of_stock']),
        'serve' => fn (OrderLine $l) => ($this->post)("order-lines/{$l->id}/served"),
    ];
    // the order's own line when the person may read the order; else the station's ticket line (the kitchen's people read the rail)
    $listed = function (OrderLine $l): array {
        $order = $this->getJson("/api/v1/orders/{$l->order_id}");
        if ($order->status() === 200) {
            return collect($order->json('lines'))->firstWhere('id', $l->id)['actions'] ?? [];
        }
        if ($l->station_id === null) {
            return [];
        }
        $rail = $this->getJson("/api/v1/stations/{$l->station_id}/tickets");
        foreach ($rail->status() === 200 ? (array) $rail->json('data') : [] as $ticket) {
            foreach ((array) ($ticket['lines'] ?? []) as $row) {
                if (($row['id'] ?? null) === $l->id) {
                    return $row['actions'];
                }
            }
        }

        return [];
    };
    $fingerprint = fn (OrderLine $l): array => [DB::table('order_lines')->where('id', $l->id)->first(['status', 'version'])];

    expect(ActionsMatrix::mismatches($records, $this->people, $calls, $listed, $fingerprint))->toBe([]);
});
