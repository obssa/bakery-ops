<?php

declare(strict_types=1);

use App\Modules\Orders\Contracts\Orders;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\ChannelTotal;
use App\Modules\Payments\Contracts\Payments;
use App\Modules\Payments\Contracts\Totals;
use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;
use App\Shared\Shifts\OpenShifts;
use App\Shared\Values\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeOpenShifts;
use Tests\Support\OrderFloor;
use Tests\Support\OrderRefundRows;

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
 * payments.require_shift_for_cash guard) were added at step 3.5, red first.
 */

beforeEach(function () {
    // both rules are on by default since step 3.7e; these cases double the shift reads themselves, so they start with the rules off and the
    // ones about a rule turn it on (payments.require_shift_for_cash below)
    config(['payments.require_shift_for_cash' => false, 'ledger.require_shift_for_station_locations' => false]);
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
            'shift_instance_id' => $shift === null ? null : shiftRow($shift, $branch->id),
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
    $orderInA = Payment::factory()->create(['branch_id' => $this->branch->id, 'shift_instance_id' => shiftRow(21, $this->branch->id), 'amount' => '80.00'])->order_id;
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
        withActor($f->cashier->id, null, null, null, shiftRow($product === 'croissant' ? 62 : 61, $this->branch->id));
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

    withActor($f->cashier->id, null, null, null, shiftRow(71, $this->branch->id));
    $a = app(Payments::class)->record($withShift['id'], (string) Str::uuid(), 'cash', $withShift['totals']['total'], null, null, $f->cashier);
    withActor($f->cashier->id);
    $b = app(Payments::class)->record($without['id'], (string) Str::uuid(), 'cash', $without['totals']['total'], null, null, $f->cashier);

    expect(Payment::query()->findOrFail($a->payment->id)->shift_instance_id)->toBe(71)
        ->and(Payment::query()->findOrFail($b->payment->id)->shift_instance_id)->toBeNull()
        ->and(app(Totals::class)->cash(71)->amount())->toBe($withShift['totals']['total'])
        ->and(app(Totals::class)->cash(72)->amount())->toBe('0.00');
})->group('contract:shifts->payments', 'contract');

it('gives the cash-declaration arithmetic on a worked table: opening float plus the cash taken is the expected figure, change given back is not counted', function () {
    // section 10.5, case 1: an opening float of 500.00, three payments with tender and change
    ($this->take)('cash', '60.00', 81, ['tendered_amount' => '100.00', 'change_amount' => '40.00']);
    ($this->take)('cash', '120.00', 81, ['tendered_amount' => '120.00', 'change_amount' => '0.00']);
    ($this->take)('cash', '45.50', 81, ['tendered_amount' => '50.00', 'change_amount' => '4.50']);
    // case 3: a payment taken in another shift and a voided one are in no figure of shift 81
    ($this->take)('cash', '30.00', 82);
    ($this->take)('cash', '80.00', 81, ['status' => 'voided']);

    $totals = app(Totals::class);
    $opening = Money::of('500.00');
    $sales = $totals->cash(81);
    $expected = $opening->plus($sales);

    expect($sales->amount())->toBe('225.50', 'tender 270.00 less change 44.50 is not what the till sold')
        ->and($expected->amount())->toBe('725.50')
        ->and(Money::of('725.50')->minus($expected)->amount())->toBe('0.00', 'counted 725.50: no variance')
        ->and(Money::of('700.00')->minus($expected)->amount())->toBe('-25.50', 'counted 700.00: short by 25.50')
        ->and(array_sum(array_map(fn (ChannelTotal $c): int => $c->receipts, $totals->byChannel(81))))->toBe(3);
})->group('contract:shifts->payments', 'contract');

it('refuses a cash payment with 409 payments.shift.required when the flag is on and the actor carries no open shift, and rolls the payment back whole', function () {
    config(['payments.require_shift_for_cash' => true]);
    $double = new FakeOpenShifts([], [91]);
    app()->instance(OpenShifts::class, $double);
    $f = OrderFloor::open($this);
    $m = $f->menu;
    $serve = function () use ($f, $m): array {
        $order = $f->send([$m->line($m->croissant)]);
        foreach ((array) $order['lines'] as $line) {
            $f->act('served', $line['id'], $f->waiter)->assertOk();
        }

        return $order;
    };
    $order = $serve();
    $before = Payment::query()->count();
    $try = function (?int $shift) use ($f, $order): ?PaymentError {
        withActor($f->cashier->id, null, null, null, $shift === null ? null : shiftRow($shift, $f->branch->id));
        try {
            app(Payments::class)->record($order['id'], (string) Str::uuid(), 'cash', $order['totals']['total'], null, null, $f->cashier);
        } catch (PaymentError $e) {
            return $e;
        }

        return null;
    };

    $none = $try(null);
    $notOpen = $try(92);

    expect($none)->toBeInstanceOf(PaymentError::class)
        ->and($none?->messageKey)->toBe('payments.shift.required')
        ->and($none?->status)->toBe(409)
        ->and($notOpen?->messageKey)->toBe('payments.shift.required', 'a shift that is not open (counting, locked) does not take cash')
        ->and(Payment::query()->count())->toBe($before, 'the refusal rolls the whole payment back')
        ->and(Order::query()->findOrFail($order['id'])->status->value)->not->toBe('closed');

    expect($try(91))->toBeNull('an open shift takes it');
    expect(Payment::query()->count())->toBe($before + 1)
        ->and(Payment::query()->latest('id')->firstOrFail()->shift_instance_id)->toBe(91);
})->group('contract:shifts->payments', 'contract');

it('never asks Shifts anything when the flag is off, whatever the actor carries', function () {
    config(['payments.require_shift_for_cash' => false]);
    $double = new FakeOpenShifts;
    app()->instance(OpenShifts::class, $double);
    $f = OrderFloor::open($this);
    $m = $f->menu;
    $order = $f->send([$m->line($m->croissant)]);
    foreach ((array) $order['lines'] as $line) {
        $f->act('served', $line['id'], $f->waiter)->assertOk();
    }
    $double->asked = [];

    withActor($f->cashier->id);
    app(Payments::class)->record($order['id'], (string) Str::uuid(), 'cash', $order['totals']['total'], null, null, $f->cashier);

    expect(array_filter($double->asked, fn (string $q): bool => str_starts_with($q, 'isOpen')))->toBe([])
        ->and(Payment::query()->count())->toBe(1);
})->group('contract:shifts->payments', 'contract');

it('takes the cash refunds given in a shift out of its cash, keyed on the refund\'s own shift, and lists each channel\'s refunds beside its takings', function () {
    // step 4.3d (phase-4 plan section 2, 10.4 and 10.9 item 13): a cash refund is handed out of the drawer of the shift it is given in, so the
    // closing declaration expects less; a digital refund leaves the drawer alone; a refund of a payment taken in another shift counts for the
    // shift that gave it, as a payment counts for the shift that took it
    $cash = ($this->take)('cash', '300.00', 61);
    $wallet = ($this->take)('telebirr', '120.00', 61, ['reference_no' => 'TB-61']);
    $older = ($this->take)('cash', '80.00', 62);
    ($this->take)('cash', '40.00', 61, ['status' => 'voided']);
    $refund = function (Payment $payment, string $amount, int $shift, ?Branch $in = null): void {
        $branch = $in ?? $this->branch;
        DB::table('payment_refunds')->insert([
            'branch_id' => $branch->id, 'client_uuid' => (string) Str::uuid(), 'order_id' => $payment->order_id,
            'order_refund_id' => OrderRefundRows::make($branch->id, $payment->order_id, $this->admin->id, $amount),
            'payment_id' => $payment->id, 'channel_id' => $payment->channel_id, 'amount' => $amount,
            'refunded_by_user_id' => $this->admin->id, 'approved_by_user_id' => $this->admin->id,
            'shift_instance_id' => shiftRow($shift, $branch->id), 'refunded_at' => now(), 'created_at' => now(),
        ]);
    };
    $refund($cash, '60.00', 61);
    $refund($older, '30.00', 61);
    $refund($wallet, '20.00', 61);
    $refund($cash, '15.00', 63);

    $totals = app(Totals::class);

    expect($totals->cash(61)->amount())->toBe('210.00', '300.00 taken, 60.00 and 30.00 handed back in the shift')
        ->and($totals->cash(62)->amount())->toBe('80.00', 'the payment\'s own shift keeps what it took')
        ->and($totals->cash(63)->amount())->toBe('-15.00', 'a shift that only gave money back expects less than its float');
    $byChannel = $totals->byChannel(61);
    $cashRow = collect($byChannel)->firstWhere('code', 'cash');
    $walletRow = collect($byChannel)->firstWhere('code', 'telebirr');
    expect($cashRow->receipts)->toBe(1)->and($cashRow->sum)->toBe('300.00')
        ->and($cashRow->refunds)->toBe(2)->and($cashRow->refundTotal)->toBe('90.00')
        ->and($walletRow->refunds)->toBe(1)->and($walletRow->refundTotal)->toBe('20.00')
        ->and($cashRow->toArray())->toHaveKeys(['refunds', 'refund_total']);
    $only = $totals->byChannel(63);
    expect($only)->toHaveCount(1)
        ->and($only[0]->code)->toBe('cash')
        ->and($only[0]->receipts)->toBe(0)
        ->and($only[0]->sum)->toBe('0.00')
        ->and($only[0]->refunds)->toBe(1)
        ->and($only[0]->refundTotal)->toBe('15.00', 'a channel that only gave money back in the shift is listed');
})->group('contract:shifts->payments', 'contract');
