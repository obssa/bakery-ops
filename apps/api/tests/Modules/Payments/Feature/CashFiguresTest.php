<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\ShortCodes;
use App\Shared\Values\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

pest()->group('module:payments', 'module:shifts', 'feature');

/*
 * CashFiguresTest (phase-4 plan 10.4 and 10.9 item 13, step 4.3d): a cash refund is handed out of the drawer, so the till's expected cash falls
 * by it. Shifts' code and its cash-declaration arithmetic do not change: Payments' Totals::cash is net of the cash refunds given in the shift,
 * and the till widget (GET /shifts/{shift}/cash) and the closing declaration read it. Without this every refund would read as a shortage.
 */

it('lowers the till\'s cash and its expected closing figure by a cash refund', function () {
    $f = OrderFloor::open($this);
    $m = $f->menu;
    withActor($f->admin->id);
    app(ShortCodes::class)->set($f->manager, '246802');
    $shift = (int) DB::table('shift_instances')->where('station_id', $m->tillStation->id)->whereNotNull('open_key')->value('id');
    $read = function () use ($f, $shift): array {
        loginAs($f->admin);

        return $this->getJson("/api/v1/shifts/{$shift}/cash")->assertOk()->json();
    };
    $order = $f->send([$m->line($m->croissant), $m->line($m->croissant)]);
    foreach ($order['lines'] as $line) {
        $f->act('served', $line['id'], $f->waiter)->assertOk();
    }
    loginAs($f->cashier);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$order['id']}/payments", [
        'client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => '120.00',
    ])->assertCreated();
    $before = $read();
    expect($before['cash_sales_total'])->toBe('120.00');

    loginAs($f->cashier);
    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson("/api/v1/orders/{$order['id']}/refund", [
        'client_uuid' => (string) Str::uuid(), 'reason' => 'The second croissant was dropped', 'pin' => '246802', 'lines' => [$order['lines'][1]['id']],
    ])->assertCreated()->assertJsonPath('payments.0.channel_code', 'cash');

    $after = $read();
    expect($after['cash_sales_total'])->toBe('60.00')
        ->and($after['expected_total'])->toBe(Money::of($before['expected_total'])->minus('60.00')->amount(), 'the drawer is expected to hold the refund less')
        ->and($after['opening_total'])->toBe($before['opening_total']);
});
