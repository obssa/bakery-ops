<?php

declare(strict_types=1);

use App\Modules\Orders\Contracts\Orders;
use App\Modules\Payments\Contracts\ChannelTotal;
use App\Modules\Payments\Contracts\Payments;
use App\Modules\Payments\Contracts\Totals;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use Tests\Support\OrderFloor;

/*
 * Contract: shifts -> payments (manifest contracts["shifts->payments"]; phase-3 plan sections 2 and 10.5).
 *
 * Written BEFORE any code exists in App\Modules\Shifts (step 3.1a): only Payments' public side (Contracts\Totals, the
 * new reads this step adds, and Payments::record), its factories and the OrderFloor. Phrased from Shifts' side: "when I
 * close a till shift I ask Payments how much cash was taken in that shift and what each channel took, and I do no
 * arithmetic of my own". The key is the payment's own shift_instance_id, the shift it was taken in: money enters the
 * drawer when the payment is taken, so a payment taken in shift B for an order created in shift A counts for B (the
 * manifest said "whose order carries that shift"; corrected in this step, ADR 0013). It runs in Payments' CI job
 * (mod-payments) for the life of the project. Cases 8 and 9 of the plan (the cash-declaration arithmetic and the
 * payments.require_shift_for_cash guard) are added at step 3.5, red first.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $this->admin = makeUser($this->branch, 'admin');
    withActor($this->admin->id);
    $this->channel = fn (string $code): PaymentChannel => PaymentChannel::query()->where('code', $code)->firstOrFail();
    // a payment row as Payments wrote it, for a shift (a row only: it moves nothing on its order)
    $this->take = function (string $channel, string $amount, ?int $shift, array $extra = [], ?Branch $in = null): Payment {
        $branch = $in ?? $this->branch;
        $row = Payment::factory()->create([
            'branch_id' => $branch->id,
            'channel_id' => PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('code', $channel)->value('id'),
            'amount' => $amount,
            'tendered_amount' => $channel === 'cash' ? $amount : null,
            'change_amount' => $channel === 'cash' ? '0.00' : null,
            'shift_instance_id' => $shift,
        ] + $extra);

        return $row;
    };
});

it('sums only the non-voided cash payments carrying that shift, as a 2 dp decimal string', function () {
    ($this->take)('cash', '100.00', 11);
    ($this->take)('cash', '250.50', 11);
    ($this->take)('cash', '0.25', 11);
    ($this->take)('cash', '40.00', 12);

    $totals = app(Totals::class);

    expect($totals->cash(11)->amount())->toBeString()->toBe('350.75')
        ->and($totals->cash(12)->amount())->toBe('40.00')
        ->and($totals->cash(13)->amount())->toBe('0.00', 'a shift with no payment')
        ->and($totals->cash(13)->currency())->toBe('ETB');
})->group('contract:shifts->payments', 'contract');

it('keys the total on the payment\'s own shift: a payment taken in shift B for an order of shift A counts for B only', function () {
    $orderInA = Payment::factory()->create(['branch_id' => $this->branch->id, 'shift_instance_id' => 21, 'amount' => '80.00'])->order_id;
    // the same order is paid later, in shift 22 (or at a pad with no shift at all)
    ($this->take)('cash', '120.00', 22, ['order_id' => $orderInA]);
    ($this->take)('cash', '30.00', null, ['order_id' => $orderInA]);

    $totals = app(Totals::class);

    expect($totals->cash(21)->amount())->toBe('80.00')
        ->and($totals->cash(22)->amount())->toBe('120.00')
        ->and(Payment::query()->whereNull('shift_instance_id')->count())->toBe(1, 'a payment with no shift is in no shift\'s total');
})->group('contract:shifts->payments', 'contract');

it('leaves out other channels, other shifts, voided payments and other branches\' payments', function () {
    ($this->take)('cash', '100.00', 31);
    ($this->take)('cash', '55.00', 31, ['status' => 'voided']);
    ($this->take)('cash', '500.00', 32);
    ($this->take)('telebirr', '70.00', 31, ['reference_no' => 'TB-1']);
    $other = Branch::factory()->create();
    ($this->take)('cash', '900.00', 31, [], $other);

    $totals = app(Totals::class);

    expect($totals->cash(31)->amount())->toBe('100.00')
        ->and($totals->cash(32)->amount())->toBe('500.00');
    $byChannel = $totals->byChannel(31);
    expect(array_map(fn (ChannelTotal $c): string => $c->code, $byChannel))->toBe(['cash', 'telebirr'])
        ->and($byChannel[0]->sum)->toBe('100.00')
        ->and($byChannel[1]->sum)->toBe('70.00');
})->group('contract:shifts->payments', 'contract');

it('sums amount, not tendered_amount or change_amount', function () {
    ($this->take)('cash', '340.00', 41, ['tendered_amount' => '500.00', 'change_amount' => '160.00']);
    ($this->take)('cash', '60.00', 41, ['tendered_amount' => '100.00', 'change_amount' => '40.00']);

    expect(app(Totals::class)->cash(41)->amount())->toBe('400.00')
        ->and(app(Totals::class)->byChannel(41)[0]->sum)->toBe('400.00');
})->group('contract:shifts->payments', 'contract');

it('counts and sums per channel for the shift, ordered by the channel\'s sort then id, without channels that took nothing', function () {
    ($this->take)('cash', '100.00', 51);
    ($this->take)('cash', '20.50', 51);
    ($this->take)('telebirr', '75.00', 51, ['reference_no' => 'TB-2']);
    ($this->take)('cbe_birr', '10.00', 51, ['reference_no' => 'CB-1']);
    ($this->take)('cash', '5.00', 52);
    ($this->channel)('cbe_birr')->forceFill(['sort' => 0])->save();

    $byChannel = app(Totals::class)->byChannel(51);

    expect($byChannel)->toHaveCount(3)
        ->and($byChannel[0])->toBeInstanceOf(ChannelTotal::class)
        ->and(array_map(fn (ChannelTotal $c): string => $c->code, $byChannel))->toBe(['cbe_birr', 'cash', 'telebirr'], 'the channel sort, not the order of the payments')
        ->and($byChannel[1]->channelId)->toBe(($this->channel)('cash')->id)
        ->and(collect($byChannel)->firstWhere('code', 'cash')->receipts)->toBe(2)
        ->and(collect($byChannel)->firstWhere('code', 'cash')->sum)->toBe('120.50')
        ->and(collect($byChannel)->firstWhere('code', 'telebirr')->receipts)->toBe(1)
        ->and(collect($byChannel)->firstWhere('code', 'cbe_birr')->sum)->toBe('10.00')
        ->and(collect($byChannel)->firstWhere('code', 'cash')->nameEn)->toBeString()->not->toBe('')
        ->and(collect($byChannel)->firstWhere('code', 'cash')->nameAm)->toBeString()->not->toBe('')
        ->and(app(Totals::class)->byChannel(59))->toBe([], 'a shift with no payment lists no channel');
})->group('contract:shifts->payments', 'contract');

it('equals the sum of the same payments read through Orders::totals, with no second arithmetic', function () {
    $f = OrderFloor::open($this);
    $m = $f->menu;
    $paid = [];
    foreach ([['sandwich', '1'], ['latte', '2'], ['croissant', '3']] as [$product, $qty]) {
        $order = $f->send([$m->line($m->{$product}, $qty)]);
        foreach ((array) $order['lines'] as $line) {
            if (($line['station']['code'] ?? null) === 'kitchen') {
                $f->act('ready', $line['id'], $f->chef)->assertOk();
            } elseif (($line['station']['code'] ?? null) === 'bar') {
                $f->act('ready', $line['id'], $f->barista)->assertOk();
            }
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }
        // two of the three are paid in shift 61, one in shift 62
        withActor($f->cashier->id, null, null, null, $product === 'croissant' ? 62 : 61);
        app(Payments::class)->record($order['id'], (string) Str::uuid(), 'cash', $order['totals']['total'], null, null, $f->cashier);
        $paid[$product] = [$order['id'], $order['totals']['total']];
    }

    $viaOrders = fn (string ...$products): string => array_reduce(
        $products,
        fn (string $carry, string $p): string => (string) BigDecimal::of($carry)->plus(app(Orders::class)->totals($paid[$p][0])->paid)->toScale(2),
        '0.00',
    );

    expect(app(Totals::class)->cash(61)->amount())->toBe($viaOrders('sandwich', 'latte'))
        ->and(app(Totals::class)->cash(62)->amount())->toBe($viaOrders('croissant'));
})->group('contract:shifts->payments', 'contract');

it('stamps a payment recorded through Payments::record with the shift the actor carries, and none when there is none', function () {
    $f = OrderFloor::open($this);
    $m = $f->menu;
    $serve = function () use ($f, $m): array {
        $order = $f->send([$m->line($m->croissant)]);
        foreach ((array) $order['lines'] as $line) {
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }

        return $order;
    };
    $withShift = $serve();
    $without = $serve();

    withActor($f->cashier->id, null, null, null, 71);
    $a = app(Payments::class)->record($withShift['id'], (string) Str::uuid(), 'cash', $withShift['totals']['total'], null, null, $f->cashier);
    withActor($f->cashier->id);
    $b = app(Payments::class)->record($without['id'], (string) Str::uuid(), 'cash', $without['totals']['total'], null, null, $f->cashier);

    expect(Payment::query()->findOrFail($a->payment->id)->shift_instance_id)->toBe(71)
        ->and(Payment::query()->findOrFail($b->payment->id)->shift_instance_id)->toBeNull()
        ->and(app(Totals::class)->cash(71)->amount())->toBe($withShift['totals']['total'])
        ->and(app(Totals::class)->cash(72)->amount())->toBe('0.00');
})->group('contract:shifts->payments', 'contract');
