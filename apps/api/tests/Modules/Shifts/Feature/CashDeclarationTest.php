<?php

declare(strict_types=1);

use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Models\CashDeclaration;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Events\CountSubmitted;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * The cash declaration of the till's drawer (step 3.5; phase-3 plan sections 3 (migration 13), 4 and 10.5). The drawer is a count like any
 * other (same lifecycle, approval, lock and chain), with a declaration instead of lines: the cashier counts notes and coins, the server
 * works out the total in Money, and
 *   opening: expected = the float the last closing left (nothing ever left: the counted figure, no variance);
 *   closing: expected = the opening count + the cash taken in the shift (Payments' Totals::cash; change given back is not counted),
 *            variance = counted - expected, float left = what stays for the next shift.
 * Any variance needs a reason, as a stock line does. The worked table of section 10.5 is the first test.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    $this->till = $this->f->till;
    $this->drawer = $this->f->drawer($this->till);
    $this->elsewhere = $this->f->running($this->f->kitchen, $this->f->chef); // a shift of another station, for the payment taken elsewhere
    // the three payments of the worked table: amount, tender
    $this->threePayments = function (int $shift): void {
        $this->f->taken($shift, '60.00', '100.00');
        $this->f->taken($shift, '120.00', '120.00');
        $this->f->taken($shift, '45.50', '50.00');
    };
    $this->declaration = fn (int $countId): CashDeclaration => CashDeclaration::query()->where('count_id', $countId)->firstOrFail();
});

it('pins the worked table of section 10.5', function (array $open, array $extra, array $counted, string $float, string $cashSales, string $expected, string $variance) {
    $cashier = $this->f->cashier;
    $opened = $this->f->openTill($this->till, $cashier, $open);
    $opened['submit']->assertOk()->assertJsonPath('status', 'locked');
    $shift = $opened['shift'];
    expect(ShiftInstance::query()->findOrFail($shift)->status->value)->toBe('open');
    if ($extra['payments'] ?? false) {
        ($this->threePayments)($shift);
        $this->f->taken($this->elsewhere, '30.00');
    }
    if ($extra['voided'] ?? false) {
        $this->f->taken($shift, '80.00', null, 'cash', ['status' => 'voided']);
    }
    $closing = $this->f->closeTill($shift, $cashier);

    $reason = $variance === '0.00' ? [] : ['reason_id' => $this->f->reasonId('miscount')];
    $this->f->cashPut($cashier, $closing['count'], ['denominations' => $counted, 'float_left_total' => $float] + $reason)->assertOk();
    $submit = $this->f->submit($cashier, $closing['count'])->assertOk();

    $submit->assertJsonPath('cash.cash_sales_total', $cashSales)
        ->assertJsonPath('cash.expected_total', $expected)
        ->assertJsonPath('cash.variance_total', $variance)
        ->assertJsonPath('cash.float_left_total', $float)
        ->assertJsonPath('cash.currency', 'ETB')
        ->assertJsonPath('status', $variance === '0.00' ? 'locked' : 'pending_approval');
})->with([
    'case 1: counted 725.50, no variance' => [['100' => 5], ['payments' => true], ['200' => 3, '100' => 1, '10' => 2, '5' => 1, '0.50' => 1], '500.00', '225.50', '725.50', '0.00'],
    'case 2: counted 700.00, short by 25.50' => [['100' => 5], ['payments' => true], ['200' => 3, '100' => 1], '500.00', '225.50', '725.50', '-25.50'],
    'case 3: another shift payment and a voided one change nothing' => [['100' => 5], ['payments' => true, 'voided' => true], ['200' => 3, '100' => 1, '10' => 2, '5' => 1, '0.50' => 1], '500.00', '225.50', '725.50', '0.00'],
    'case 4: nothing ever before, counted 300.00, no payments' => [['100' => 3], [], ['100' => 3], '100.00', '0.00', '300.00', '0.00'],
]);

it('makes the first opening count of a till the expected figure itself: no carried float, no variance, the shift opens', function () {
    $o = $this->f->openTill($this->till, $this->f->cashier, ['100' => 3, '0.50' => 1]);

    $o['submit']->assertOk()
        ->assertJsonPath('status', 'locked')
        ->assertJsonPath('cash.counted_total', '300.50')
        ->assertJsonPath('cash.expected_total', '300.50')
        ->assertJsonPath('cash.variance_total', '0.00')
        ->assertJsonPath('cash.carried_float_total', null)
        ->assertJsonPath('cash.cash_sales_total', null)
        ->assertJsonPath('cash.float_left_total', null)
        ->assertJsonPath('cash.channels', []);
    expect(ShiftInstance::query()->findOrFail($o['shift'])->status->value)->toBe('open');
});

it('carries the float the last closing left into the next opening, and asks a reason for a difference', function () {
    $cashier = $this->f->cashier;
    $first = $this->f->openTill($this->till, $cashier, ['100' => 5]);
    $closing = $this->f->closeTill($first['shift'], $cashier);
    $this->f->cashPut($cashier, $closing['count'], ['denominations' => ['100' => 5], 'float_left_total' => '300.00'])->assertOk();
    $this->f->submit($cashier, $closing['count'])->assertOk()->assertJsonPath('status', 'locked');
    expect(ShiftInstance::query()->findOrFail($first['shift'])->status->value)->toBe('locked');

    $start = $this->f->start($cashier, $this->till)->assertCreated();
    $count = (int) collect($start->json('counts'))->firstWhere('location.type', 'cash_drawer')['id'];
    $this->f->cashPut($cashier, $count, ['denominations' => ['100' => 2, '10' => 9]])->assertOk()
        ->assertJsonPath('cash.carried_float_total', '300.00')
        ->assertJsonPath('cash.expected_total', '300.00')
        ->assertJsonPath('cash.counted_total', '290.00')
        ->assertJsonPath('cash.variance_total', '-10.00');
    $this->f->submit($cashier, $count)->assertUnprocessable()->assertJsonPath('message_key', 'shifts.cash.reason_required');

    $this->f->cashPut($cashier, $count, ['denominations' => ['100' => 2, '10' => 9], 'reason_id' => $this->f->reasonId('miscount')])->assertOk();
    $this->f->submit($cashier, $count)->assertOk()
        ->assertJsonPath('status', 'pending_approval')
        ->assertJsonPath('cash.variance_total', '-10.00')
        ->assertJsonPath('cash.reason.code', 'miscount');
    $this->f->approve($this->f->manager, $count)->assertOk()->assertJsonPath('status', 'locked');
});

it('counts the closing against the opening figure that was counted, not the one that was carried', function () {
    $cashier = $this->f->cashier;
    $first = $this->f->openTill($this->till, $cashier, ['100' => 5]);
    $c1 = $this->f->closeTill($first['shift'], $cashier);
    $this->f->cashPut($cashier, $c1['count'], ['denominations' => ['100' => 5], 'float_left_total' => '500.00'])->assertOk();
    $this->f->submit($cashier, $c1['count'])->assertOk();

    // next morning the drawer holds 480.00, not 500.00: counted, explained, approved
    $second = $this->f->start($cashier, $this->till)->assertCreated();
    $open = (int) collect($second->json('counts'))->firstWhere('location.type', 'cash_drawer')['id'];
    $this->f->cashPut($cashier, $open, ['denominations' => ['100' => 4, '10' => 8], 'reason_id' => $this->f->reasonId('miscount')])->assertOk();
    $this->f->submit($cashier, $open)->assertOk()->assertJsonPath('status', 'pending_approval');
    $this->f->approve($this->f->manager, $open)->assertOk();
    $shift = (int) $second->json('shift.id');
    expect(ShiftInstance::query()->findOrFail($shift)->status->value)->toBe('open');
    $this->f->taken($shift, '100.00');

    $c2 = $this->f->closeTill($shift, $cashier);
    $this->f->cashPut($cashier, $c2['count'], ['denominations' => ['100' => 5, '50' => 1, '10' => 3], 'float_left_total' => '100.00'])->assertOk();
    $this->f->submit($cashier, $c2['count'])->assertOk()
        ->assertJsonPath('status', 'locked')
        ->assertJsonPath('cash.expected_total', '580.00')
        ->assertJsonPath('cash.variance_total', '0.00');
});

it('shows a draft its figures live: the cash taken after the declaration is in them, and the submit fixes them', function () {
    $cashier = $this->f->cashier;
    $o = $this->f->openTill($this->till, $cashier, ['100' => 5]);
    $c = $this->f->closeTill($o['shift'], $cashier);
    $this->f->cashPut($cashier, $c['count'], ['denominations' => ['100' => 6], 'float_left_total' => '100.00'])->assertOk();
    $this->f->taken($o['shift'], '40.00', '50.00');

    loginAs($cashier);
    $this->getJson('/api/v1/stock-counts/'.$c['count'])->assertOk()
        ->assertJsonPath('cash.cash_sales_total', '40.00')
        ->assertJsonPath('cash.expected_total', '540.00')
        ->assertJsonPath('cash.variance_total', '60.00');
    $row = ($this->declaration)($c['count']);
    expect($row->expected_total)->toBeNull('a draft stores no figure it can still be wrong about')
        ->and($row->cash_sales_total)->toBeNull();

    $this->f->taken($o['shift'], '60.00'); // taken after the screen was drawn, before the submit
    $this->f->cashPut($cashier, $c['count'], ['denominations' => ['100' => 6], 'float_left_total' => '100.00', 'reason_id' => $this->f->reasonId('other'), 'reason_note' => 'a deposit'])->assertOk();
    $this->f->submit($cashier, $c['count'])->assertOk()->assertJsonPath('cash.cash_sales_total', '100.00')->assertJsonPath('cash.expected_total', '600.00');
    $row = ($this->declaration)($c['count']);
    expect($row->expected_total?->amount())->toBe('600.00')
        ->and($row->cash_sales_total?->amount())->toBe('100.00')
        ->and($row->variance_total?->amount())->toBe('0.00');
});

it('refuses to submit a drawer that was never declared, and a closing that does not say what stays in the drawer', function () {
    $cashier = $this->f->cashier;
    $start = $this->f->start($cashier, $this->till)->assertCreated();
    $count = (int) collect($start->json('counts'))->firstWhere('location.type', 'cash_drawer')['id'];
    $this->f->submit($cashier, $count)->assertUnprocessable()->assertJsonPath('message_key', 'shifts.cash.declaration_missing');

    $this->f->cashPut($cashier, $count, ['denominations' => ['100' => 1]])->assertOk();
    $this->f->submit($cashier, $count)->assertOk();
    $shift = (int) $start->json('shift.id');
    $closing = $this->f->closeTill($shift, $cashier);
    $this->f->submit($cashier, $closing['count'])->assertUnprocessable()->assertJsonPath('message_key', 'shifts.cash.declaration_missing');
    $this->f->cashPut($cashier, $closing['count'], ['denominations' => ['100' => 1]])->assertOk();
    $this->f->submit($cashier, $closing['count'])->assertUnprocessable()->assertJsonPath('message_key', 'shifts.cash.float_required');
    expect(StockCount::query()->findOrFail($closing['count'])->status->value)->toBe('draft');
});

it('asks for a note when the reason needs one', function () {
    $cashier = $this->f->cashier;
    $o = $this->f->openTill($this->till, $cashier, ['100' => 5]);
    $c = $this->f->closeTill($o['shift'], $cashier);
    $body = ['denominations' => ['100' => 4], 'float_left_total' => '100.00', 'reason_id' => $this->f->reasonId('other')];
    $this->f->cashPut($cashier, $c['count'], $body)->assertOk();

    $this->f->submit($cashier, $c['count'])->assertUnprocessable()->assertJsonPath('message_key', 'shifts.cash.note_required');
    $this->f->cashPut($cashier, $c['count'], $body + ['reason_note' => 'banked 100.00 at noon'])->assertOk();
    $this->f->submit($cashier, $c['count'])->assertOk()->assertJsonPath('status', 'pending_approval')->assertJsonPath('cash.note', 'banked 100.00 at noon');
});

it('waits for approval like a count with a variance, then locks the shift with its last count', function () {
    $cashier = $this->f->cashier;
    $o = $this->f->openTill($this->till, $cashier, ['100' => 5]);
    ($this->threePayments)($o['shift']);
    $c = $this->f->closeTill($o['shift'], $cashier);
    $this->f->cashPut($cashier, $c['count'], ['denominations' => ['200' => 3, '100' => 1], 'float_left_total' => '500.00', 'reason_id' => $this->f->reasonId('miscount')])->assertOk();
    $this->f->submit($cashier, $c['count'])->assertOk()->assertJsonPath('status', 'pending_approval');
    expect(ShiftInstance::query()->findOrFail($o['shift'])->status->value)->toBe('pending_approval');

    $this->f->approve($this->f->manager, $c['count'])->assertOk()
        ->assertJsonPath('status', 'locked')
        ->assertJsonPath('chain.chain_seq', 2)
        ->assertJsonPath('cash.variance_total', '-25.50');
    expect(ShiftInstance::query()->findOrFail($o['shift'])->status->value)->toBe('locked');
});

it('writes the cash block into the locked payload, and the chain verifies it', function () {
    $cashier = $this->f->cashier;
    $o = $this->f->openTill($this->till, $cashier, ['100' => 5]);
    ($this->threePayments)($o['shift']);
    $c = $this->f->closeTill($o['shift'], $cashier);
    $counted = ['200' => 3, '100' => 1, '10' => 2, '5' => 1, '0.50' => 1];
    $this->f->cashPut($cashier, $c['count'], ['denominations' => $counted, 'float_left_total' => '500.00'])->assertOk();
    $this->f->submit($cashier, $c['count'])->assertOk()->assertJsonPath('status', 'locked');

    $opening = json_decode((string) StockCount::query()->findOrFail($o['count'])->canonical_payload, true, flags: JSON_THROW_ON_ERROR);
    $closing = json_decode((string) StockCount::query()->findOrFail($c['count'])->canonical_payload, true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($closing['cash']))->toEqualCanonicalizing(['denominations', 'counted_total', 'expected_total', 'variance_total', 'carried_float_total', 'cash_sales_total', 'float_left_total', 'channels', 'reason_code', 'reason_note', 'currency'])
        ->and($closing['lines'])->toBe([])
        ->and($closing['cash']['counted_total'])->toBe('725.50')
        ->and($closing['cash']['expected_total'])->toBe('725.50')
        ->and($closing['cash']['variance_total'])->toBe('0.00')
        ->and($closing['cash']['cash_sales_total'])->toBe('225.50')
        ->and($closing['cash']['float_left_total'])->toBe('500.00')
        ->and($closing['cash']['carried_float_total'])->toBeNull()
        ->and($closing['cash']['currency'])->toBe('ETB')
        ->and($closing['cash']['denominations'])->toBe(['200' => 3, '100' => 1, '50' => 0, '10' => 2, '5' => 1, '1' => 0, '0.50' => 1, '0.25' => 0, '0.10' => 0, '0.05' => 0])
        ->and($closing['cash']['channels'])->toBe([['code' => 'cash', 'declared_receipts' => null, 'declared_sum' => null, 'difference_sum' => null, 'expected_receipts' => 3, 'expected_sum' => '225.50']])
        ->and($opening['cash']['counted_total'])->toBe('500.00')
        ->and($opening['cash']['channels'])->toBe([])
        ->and($opening['cash']['cash_sales_total'])->toBeNull();
    expect(app(HashChain::class)->verify($this->drawer->id)->toArray())->toMatchArray(['ok' => true, 'checked' => 2]);
});

it('copies the declaration into a recount, unconfirmed, and into an unlock revision, unconfirmed', function () {
    $cashier = $this->f->cashier;
    $o = $this->f->openTill($this->till, $cashier, ['100' => 5]);
    $c = $this->f->closeTill($o['shift'], $cashier);
    $body = ['denominations' => ['100' => 4], 'float_left_total' => '100.00', 'reason_id' => $this->f->reasonId('miscount'), 'reason_note' => 'short'];
    $this->f->cashPut($cashier, $c['count'], $body)->assertOk();
    $this->f->submit($cashier, $c['count'])->assertOk()->assertJsonPath('status', 'pending_approval');

    $rejected = $this->f->reject($this->f->manager, $c['count'], ['note' => 'count again'])->assertOk();
    $recount = (int) $rejected->json('recount.id');
    $rejected->assertJsonPath('recount.cash.counted_total', '400.00')
        ->assertJsonPath('recount.cash.float_left_total', '100.00')
        ->assertJsonPath('recount.cash.confirmed', false)
        ->assertJsonPath('recount.cash.reason.code', 'miscount')
        ->assertJsonPath('recount.cash.note', 'short');
    $this->f->submit($cashier, $recount)->assertUnprocessable()->assertJsonPath('message_key', 'shifts.cash.declaration_missing');
    $this->f->cashPut($cashier, $recount, ['denominations' => ['100' => 5], 'float_left_total' => '100.00'])->assertOk();
    $this->f->submit($cashier, $recount)->assertOk()->assertJsonPath('status', 'locked');

    $unlocked = $this->f->send('POST', "/api/v1/stock-counts/{$recount}/unlock", $this->f->admin, ['reason' => 'the bank slip was found'])->assertOk();
    $revision = (int) $unlocked->json('revision.id');
    $unlocked->assertJsonPath('revision.cash.counted_total', '500.00')
        ->assertJsonPath('revision.cash.confirmed', false)
        ->assertJsonPath('revision.cash.float_left_total', '100.00');
    expect(($this->declaration)($revision)->count_id)->toBe($revision)
        ->and(CashDeclaration::query()->count())->toBe(4, 'opening, closing, recount, revision');
});

it('announces the cash variance with the count', function () {
    Event::fake([CountSubmitted::class]);
    $cashier = $this->f->cashier;
    $o = $this->f->openTill($this->till, $cashier, ['100' => 5]);
    $c = $this->f->closeTill($o['shift'], $cashier);
    $this->f->cashPut($cashier, $c['count'], ['denominations' => ['100' => 4], 'float_left_total' => '100.00', 'reason_id' => $this->f->reasonId('miscount')])->assertOk();
    $this->f->submit($cashier, $c['count'])->assertOk();

    Event::assertDispatched(CountSubmitted::class, fn (CountSubmitted $e): bool => $e->countId === $c['count'] && $e->cashVarianceTotal === '-100.00');
    Event::assertDispatched(CountSubmitted::class, fn (CountSubmitted $e): bool => $e->countId === $o['count'] && $e->cashVarianceTotal === '0.00');
});

it('makes the drawer part of the shift beside the stock places: both must be handed in before the shift opens', function () {
    $cashier = $this->f->cashier;
    $case = $this->f->place($this->till);
    $start = $this->f->start($cashier, $this->till)->assertCreated();
    $shift = (int) $start->json('shift.id');

    expect($start->json('counts'))->toHaveCount(2)
        ->and(collect($start->json('counts'))->pluck('location.type')->sort()->values()->all())->toBe(['cash_drawer', 'display_case']);
    $stock = (int) collect($start->json('counts'))->firstWhere('location.id', $case->id)['id'];
    $drawer = (int) collect($start->json('counts'))->firstWhere('location.type', 'cash_drawer')['id'];
    $this->f->submit($cashier, $stock)->assertOk();
    expect(ShiftInstance::query()->findOrFail($shift)->status->value)->toBe('opening');

    $this->f->cashPut($cashier, $drawer, ['denominations' => ['100' => 1]])->assertOk();
    $this->f->submit($cashier, $drawer)->assertOk();
    expect(ShiftInstance::query()->findOrFail($shift)->status->value)->toBe('open');

    $close = $this->f->close($cashier, $shift)->assertOk();
    expect($close->json('counts'))->toHaveCount(2);
});

it('does not give a station without a cash drawer a cash count', function () {
    $this->f->place($this->f->kitchen);
    $start = $this->f->start($this->f->chef, $this->f->kitchen)->assertCreated();

    expect(collect($start->json('counts'))->pluck('cash')->filter()->all())->toBe([])
        ->and(CashDeclaration::query()->count())->toBe(0);
});
