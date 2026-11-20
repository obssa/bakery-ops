<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActionsMatrix;
use Tests\Support\OrderFloor;

pest()->group('module:payments', 'feature');

/*
 * The `actions` of a payment (ADR 0032): for every state a payment can be in (and the states of its order that matter) and every role
 * of the bakery, the list GET /payments/{id} gives that person is exactly the set of decisions (verify, dispute, void) the endpoints
 * accept from that person now and that change the payment. The payments screens and the void dialog read the list.
 */

beforeEach(function () {
    Storage::fake(config('files.disk'));
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));
    $this->floor = OrderFloor::open($this);
    $f = $this->floor;
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    stockIn($f->menu->water, $f->menu->display, '50');
    $this->post = fn (string $uri, array $body = []): TestResponse => $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/{$uri}", $body);
});

it('lists exactly the decisions the endpoints accept, in every state and for every role', function () {
    $f = $this->floor;
    $m = $f->menu;
    // two waters, served: an order of 60.00; $amount of it paid in cash at the till
    $paid = function (string $amount = '20.00') use ($f, $m): Payment {
        $order = $f->send([$m->line($m->water, '2')]);
        $f->act('served', $order['lines'][0]['id'], $f->waiter)->assertOk();
        loginAs($f->cashier);
        $total = Order::query()->findOrFail($order['id'])->total->amount();
        $id = ($this->post)("orders/{$order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => $amount === 'all' ? $total : $amount])
            ->assertCreated()->json('payment.id');

        return Payment::query()->findOrFail($id);
    };
    $decided = function (string $what, array $body) use ($f, $paid): Payment {
        $payment = $paid();
        loginAs($what === 'void' ? $f->cashier : $f->admin);
        ($this->post)("payments/{$payment->id}/{$what}", $body)->assertOk();

        return $payment->fresh();
    };
    $records = [
        'recorded, the order not settled' => fn (): Payment => $paid(),
        'recorded, the order closed' => fn (): Payment => $paid('all'),
        'verified' => fn (): Payment => $decided('verify', ['note' => 'Seen in the drawer']),
        'disputed' => fn (): Payment => $decided('dispute', ['reason' => 'Not in the drawer']),
        'voided' => fn (): Payment => $decided('void', ['reason' => 'Keyed twice', 'pin' => '246802']),
        'refunded in part' => function () use ($f, $paid): Payment {
            $payment = $paid('all');
            loginAs($f->cashier);
            ($this->post)("orders/{$payment->order_id}/refund", ['client_uuid' => (string) Str::uuid(), 'reason' => 'Brought back', 'pin' => '246802'])->assertSuccessful();

            return $payment->fresh();
        },
    ];
    $people = [
        'admin' => $f->admin, 'manager' => $f->manager, 'cashier' => $f->cashier, 'waiter' => $f->waiter, 'chef' => $f->chef,
        'barista' => $f->barista, 'cake baker' => $f->cakeBaker,
    ];
    $calls = [
        'verify' => fn (Payment $p) => ($this->post)("payments/{$p->id}/verify", ['note' => 'Seen in the drawer']),
        'dispute' => fn (Payment $p) => ($this->post)("payments/{$p->id}/dispute", ['reason' => 'Not in the drawer']),
        'void' => fn (Payment $p) => ($this->post)("payments/{$p->id}/void", ['reason' => 'Keyed twice', 'pin' => '246802']),
    ];
    $listed = function (Payment $p): array {
        $response = $this->getJson("/api/v1/payments/{$p->id}");

        return $response->status() === 200 ? $response->json('actions') : [];
    };
    $fingerprint = fn (Payment $p): array => [DB::table('payments')->where('id', $p->id)->first(['status', 'verified_at', 'disputed_at', 'voided_at'])];

    expect(ActionsMatrix::mismatches($records, $people, $calls, $listed, $fingerprint))->toBe([]);
});
