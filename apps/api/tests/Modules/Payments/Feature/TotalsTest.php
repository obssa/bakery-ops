<?php

declare(strict_types=1);

use App\Modules\Payments\Contracts\ChannelTotal;
use App\Modules\Payments\Contracts\Totals;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentChannel;
use App\Shared\Models\Branch;

pest()->group('module:payments', 'feature');

/*
 * Step 3.1a (F5, phase-3 plan sections 2 and 10.5): Totals::cash and Totals::byChannel, the reads a till shift closes
 * with. The consumer's view of them is tests/Contract/Shifts/PaymentsContractTest.php; this file adds what belongs to
 * Payments itself: a removed channel's payments still count, the figures are Money and exact strings, and a second branch
 * with the same shift id is never mixed in.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    withActor(makeUser($this->branch, 'admin')->id);
    $this->take = fn (string $channel, string $amount, ?int $shift, ?Branch $in = null): Payment => Payment::factory()->create([
        'branch_id' => ($in ?? $this->branch)->id,
        'channel_id' => PaymentChannel::query()->withoutGlobalScopes()->where('branch_id', ($in ?? $this->branch)->id)->where('code', $channel)->value('id'),
        'amount' => $amount,
        'shift_instance_id' => $shift,
    ]);
});

it('adds cents exactly and answers Money in the branch currency', function () {
    ($this->take)('cash', '0.10', 5);
    ($this->take)('cash', '0.20', 5);
    ($this->take)('cash', '1234567.89', 5);

    $cash = app(Totals::class)->cash(5);

    expect($cash->amount())->toBe('1234568.19')->and($cash->currency())->toBe('ETB');
})->group('module:payments', 'feature');

it('still counts the payments of a channel that was removed afterwards', function () {
    ($this->take)('cash', '40.00', 6);
    ($this->take)('telebirr', '15.00', 6);
    PaymentChannel::query()->where('code', 'telebirr')->firstOrFail()->delete();

    $byChannel = app(Totals::class)->byChannel(6);

    expect(array_map(fn (ChannelTotal $c): string => $c->code, $byChannel))->toBe(['cash', 'telebirr'])
        ->and($byChannel[1]->toArray())->toMatchArray(['code' => 'telebirr', 'receipts' => 1, 'sum' => '15.00']);
})->group('module:payments', 'feature');

it('keeps two branches that use the same shift id apart', function () {
    $other = Branch::factory()->create();
    ($this->take)('cash', '10.00', 7);
    ($this->take)('cash', '99.00', 7, $other);

    expect(app(Totals::class)->cash(7)->amount())->toBe('10.00')
        ->and(app(Totals::class)->byChannel(7)[0]->sum)->toBe('10.00')
        ->and(app(Totals::class)->byChannel(7)[0]->receipts)->toBe(1);
})->group('module:payments', 'feature');
