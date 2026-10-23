<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Shifts\Models\ShiftInstance;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\OrderFloor;

pest()->group('module:payments', 'feature');

/*
 * payments.require_shift_for_cash (step 3.5; phase-3 plan sections 2 (F5) and 10.1): the guard in RecordPayment. With the flag on, a cash
 * payment needs the actor to carry an OPEN shift (409 payments.shift.required): a payment taken after the till's count began would fall
 * outside the declared cash, and one with no shift would be in no drawer's total. The refusal rolls the whole payment back. The flag is
 * OFF by default everywhere, the live host included (config, .env.example, .env.ci) until step 3.7e flips it with the ledger's and the
 * call-in switches; with it off nothing about payments changes and Shifts is never asked.
 */

beforeEach(function () {
    $this->floor = OrderFloor::open($this);
    $f = $this->floor;
    $f->menu->tillStation->forceFill(['tracks_shifts' => true])->save();
    // the floor opens the till's shift and assigns the cashier to it when a shift rule is on (the default); these tests start from a
    // cashier who works nowhere, and the helper below takes the floor's till shift to the state a test needs
    $f->assign($f->cashier);
    $this->order = function () use ($f): array {
        $m = $f->menu;
        $order = $f->send([$m->line($m->croissant)]);
        foreach ($order['lines'] as $line) {
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }

        return $order;
    };
    $this->pay = function (array $order, array $body = []): TestResponse {
        loginAs($this->floor->cashier);

        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])->postJson(
            "/api/v1/orders/{$order['id']}/payments",
            $body + ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => $order['totals']['total']],
        );
    };
    $this->till = function (string $state) use ($f): ShiftInstance {
        $f->assign($f->cashier, $f->menu->tillStation);
        $shift = ShiftInstance::query()->where('station_id', $f->menu->tillStation->id)->whereNotNull('open_key')->first();
        if (! $shift instanceof ShiftInstance) {
            return ShiftInstance::factory()->{$state}()->create([
                'branch_id' => $f->branch->id, 'station_id' => $f->menu->tillStation->id, 'created_by_user_id' => $f->cashier->id,
            ]);
        }

        if ($state !== 'open') {
            $shift->forceFill(['status' => $state])->save();
        }

        return $shift;
    };
});

it('ships on (step 3.7e): the config default and the env files of the repository say true', function () {
    expect(config('payments.require_shift_for_cash'))->toBeTrue();
    foreach (['.env.example', '.env.ci'] as $file) {
        $text = (string) file_get_contents(base_path($file));
        expect(str_contains($text, 'PAYMENTS_REQUIRE_SHIFT_FOR_CASH=true'))->toBeTrue("{$file} names the switch and leaves it on");
    }
});

it('takes cash with no shift at all while the flag is off (the kill switch), as before', function () {
    config(['payments.require_shift_for_cash' => false]);
    $order = ($this->order)();

    ($this->pay)($order)->assertSuccessful();

    expect(Payment::query()->count())->toBe(1)
        ->and(Payment::query()->sole()->shift_instance_id)->toBeNull();
});

it('refuses cash with 409 payments.shift.required when the flag is on and the cashier carries no shift, and writes nothing', function () {
    config(['payments.require_shift_for_cash' => true]);
    $order = ($this->order)();

    ($this->pay)($order)->assertStatus(409)
        ->assertJsonPath('message_key', 'payments.shift.required')
        ->assertJsonPath('message_en', fn ($m): bool => is_string($m) && $m !== '' && $m !== 'payments.shift.required')
        ->assertJsonPath('message_am', fn ($m): bool => is_string($m) && $m !== '' && $m !== 'payments.shift.required');

    expect(Payment::query()->count())->toBe(0)
        ->and(Order::query()->findOrFail($order['id'])->status->value)->not->toBe('closed');
});

it('takes cash into the open till shift and stamps the payment with it', function () {
    config(['payments.require_shift_for_cash' => true]);
    $shift = ($this->till)('open');
    $order = ($this->order)();

    ($this->pay)($order)->assertSuccessful();

    expect(Payment::query()->sole()->shift_instance_id)->toBe($shift->id);
});

it('takes no cash once the till is counting: the drawer is frozen', function () {
    config(['payments.require_shift_for_cash' => true]);
    ($this->till)('counting');
    $order = ($this->order)();

    ($this->pay)($order)->assertStatus(409)->assertJsonPath('message_key', 'payments.shift.required');

    expect(Payment::query()->count())->toBe(0);
});

it('takes no cash from a cashier who works at no station with a shift', function () {
    config(['payments.require_shift_for_cash' => true]);
    ($this->till)('open');
    $stray = makeUser($this->floor->branch, 'cashier');
    $order = ($this->order)();
    loginAs($stray);

    $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
        ->postJson("/api/v1/orders/{$order['id']}/payments", ['client_uuid' => (string) Str::uuid(), 'channel_code' => 'cash', 'amount' => $order['totals']['total']])
        ->assertStatus(409)->assertJsonPath('message_key', 'payments.shift.required');
});

it('answers a payment that was already taken again, even after the till began counting', function () {
    config(['payments.require_shift_for_cash' => true]);
    $shift = ($this->till)('open');
    $order = ($this->order)();
    $uuid = (string) Str::uuid();
    $first = ($this->pay)($order, ['client_uuid' => $uuid])->assertSuccessful();

    $shift->forceFill(['status' => 'counting'])->save();
    $again = ($this->pay)($order, ['client_uuid' => $uuid])->assertSuccessful();

    expect(Payment::query()->count())->toBe(1)
        ->and($again->json('payment.id') ?? $again->json('id'))->toBe($first->json('payment.id') ?? $first->json('id'));
});
