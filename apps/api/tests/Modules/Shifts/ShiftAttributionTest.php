<?php

declare(strict_types=1);

use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;
use Tests\Support\ProductionFixture;

pest()->group('module:shifts', 'feature');

/*
 * ADR 0013 rules 2 and 3, through the whole stack: once a shift runs at the station a person works at, every write that person
 * makes carries its id (ResolveActor reads it through Shared's OpenShifts, which Shifts binds); before it there is none. An order
 * belongs to the shift where it was created, a payment to the shift where the money was taken, a movement to the actor's shift.
 */

function startShiftAs(object $test, object $person, object $station): int
{
    loginAs($person);

    return (int) $test->withHeaders(idempotencyKey())
        ->postJson("/api/v1/stations/{$station->id}/shifts/start", ['client_uuid' => (string) Str::uuid()])
        ->assertCreated()->json('shift.id');
}

it('stamps orders, payments and the movements they make with the shift running at the till, and nothing before it', function () {
    // these cases are about what a shift stamps, and start from stations nobody has opened (the floors open them when a rule is on, the default since step 3.7e)
    config(['ledger.require_shift_for_station_locations' => false, 'payments.require_shift_for_cash' => false, 'shifts.open_ad_hoc_on_accept' => false]);
    $f = OrderFloor::open($this);
    $m = $f->menu;
    $f->assign($f->waiter, $m->tillStation);
    $f->assign($f->cashier, $m->tillStation);

    $sell = function () use ($f, $m): array {
        $order = $f->send([$m->line($m->croissant)]);
        foreach ($order['lines'] as $line) {
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }
        loginAs($f->cashier);
        $this->withHeaders(idempotencyKey())->postJson("/api/v1/orders/{$order['id']}/payments", [
            'client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => $order['totals']['total'], 'tendered_amount' => $order['totals']['total'],
        ])->assertCreated();

        return [
            'order' => DB::table('orders')->where('id', $order['id'])->value('shift_instance_id'),
            'payment' => DB::table('payments')->where('order_id', $order['id'])->value('shift_instance_id'),
            'movements' => DB::table('stock_movements')->where('reservation_ref', $f->refOf($f->lineOf($order, $m->croissant)['client_uuid']))->orderBy('id')->pluck('shift_instance_id', 'reason')->all(),
        ];
    };

    $before = $sell();
    expect($before['order'])->toBeNull()
        ->and($before['payment'])->toBeNull()
        ->and(array_keys($before['movements']))->toBe(['reservation', 'sale'])
        ->and(array_unique(array_values($before['movements'])))->toBe([null]);

    $shiftId = startShiftAs($this, $f->cashier, $m->tillStation);
    $after = $sell();

    expect($after['order'])->toBe($shiftId)
        ->and($after['payment'])->toBe($shiftId)
        ->and($after['movements'])->toBe(['reservation' => $shiftId, 'sale' => $shiftId]);
});

it('stamps a production request made while the cashier\'s shift runs, and a request made before it carries none', function () {
    // these cases are about what a shift stamps, and start from stations nobody has opened (the floors open them when a rule is on, the default since step 3.7e)
    config(['ledger.require_shift_for_station_locations' => false, 'payments.require_shift_for_cash' => false, 'shifts.open_ad_hoc_on_accept' => false]);
    $branch = actInBranch(Branch::factory()->create());
    $f = ProductionFixture::build($branch);
    $ask = function ($product) use ($f): int {
        loginAs($f->cashier);

        return (int) $this->withHeaders(idempotencyKey())
            ->postJson('/api/v1/production/requests', ['client_uuid' => (string) Str::uuid(), 'product_item_id' => $product->id, 'qty' => '12'])
            ->assertCreated()->json('id');
    };

    $first = $ask($f->cake);
    expect(ProductionRequest::query()->findOrFail($first)->shift_instance_id)->toBeNull();

    $shiftId = startShiftAs($this, $f->cashier, $f->tillStation);
    $second = $ask($f->bread);

    expect(ProductionRequest::query()->findOrFail($second)->shift_instance_id)->toBe($shiftId);
});

it('stamps nothing for a person whose station has no running shift, even when another station does', function () {
    // these cases are about what a shift stamps, and start from stations nobody has opened (the floors open them when a rule is on, the default since step 3.7e)
    config(['ledger.require_shift_for_station_locations' => false, 'payments.require_shift_for_cash' => false, 'shifts.open_ad_hoc_on_accept' => false]);
    $branch = actInBranch(Branch::factory()->create());
    $f = ProductionFixture::build($branch);
    startShiftAs($this, $f->baker, $f->cakeRoomStation);
    loginAs($f->cashier);
    $id = (int) $this->withHeaders(idempotencyKey())
        ->postJson('/api/v1/production/requests', ['client_uuid' => (string) Str::uuid(), 'product_item_id' => $f->cake->id, 'qty' => '12'])
        ->assertCreated()->json('id');

    expect(ProductionRequest::query()->findOrFail($id)->shift_instance_id)->toBeNull('the cashier works at the till, which has no shift');
});
