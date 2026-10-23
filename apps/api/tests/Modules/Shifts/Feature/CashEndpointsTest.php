<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Models\Location;
use App\Modules\Shifts\Models\CashDeclaration;
use App\Modules\Shifts\Models\ShiftInstance;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * The cash endpoints of step 3.5 (phase-3 plan section 4, "Cash (3.5)"):
 *   PUT /stock-counts/{count}/cash   shifts.count at the till: the declaration of the cash-drawer count, draft only
 *   GET /shifts/{shift}/cash         shifts.view at the till: the till widget's "cash in the drawer so far", live from Payments' Totals
 * Every write is an AuditedAction (count.cash) behind an Idempotency-Key; another branch's count or shift is 404.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    $this->till = $this->f->till;
    $this->drawer = $this->f->drawer($this->till);
    $this->start = $this->f->start($this->f->cashier, $this->till)->assertCreated();
    $this->shift = (int) $this->start->json('shift.id');
    $this->count = (int) collect($this->start->json('counts'))->firstWhere('location.type', 'cash_drawer')['id'];
});

it('declares the drawer: the server works out the total, every denomination is answered, the version moves', function () {
    $before = StockCount::query()->findOrFail($this->count)->version;

    $response = $this->f->cashPut($this->f->cashier, $this->count, ['denominations' => ['200' => 2, '50' => 1, '0.50' => 3]])->assertOk();

    $response->assertJsonPath('id', $this->count)
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('cash.counted_total', '451.50')
        ->assertJsonPath('cash.confirmed', true)
        ->assertJsonPath('cash.currency', 'ETB');
    expect($response->json('cash.denominations'))->toBe(['200' => 2, '100' => 0, '50' => 1, '10' => 0, '5' => 0, '1' => 0, '0.50' => 3, '0.25' => 0, '0.10' => 0, '0.05' => 0])
        ->and(StockCount::query()->findOrFail($this->count)->version)->toBe($before + 1);
    $row = CashDeclaration::query()->where('count_id', $this->count)->sole();
    expect($row->counted_total->amount())->toBe('451.50')
        ->and($row->shift_instance_id)->toBe($this->shift)
        ->and($row->branch_id)->toBe($this->branch->id)
        ->and($row->confirmed_at)->not->toBeNull();
});

it('ignores a total sent by the client: counted_total is always the server\'s', function () {
    $this->f->cashPut($this->f->cashier, $this->count, ['denominations' => ['100' => 1], 'counted_total' => '99999.00'])->assertOk()
        ->assertJsonPath('cash.counted_total', '100.00');
});

it('keeps what a later request leaves out, and clears what it sets to null', function () {
    $c = $this->f->cashier;
    $reason = $this->f->reasonId('miscount');
    $this->f->cashPut($c, $this->count, ['denominations' => ['100' => 1], 'reason_id' => $reason, 'reason_note' => 'x'])->assertOk();

    $this->f->cashPut($c, $this->count, ['denominations' => ['100' => 2]])->assertOk()
        ->assertJsonPath('cash.counted_total', '200.00')
        ->assertJsonPath('cash.reason.code', 'miscount')
        ->assertJsonPath('cash.note', 'x');
    $this->f->cashPut($c, $this->count, ['denominations' => ['100' => 2], 'reason_id' => null, 'reason_note' => null])->assertOk()
        ->assertJsonPath('cash.reason', null)
        ->assertJsonPath('cash.note', null);
});

it('refuses what cannot be a count of money, naming it, and writes nothing', function (array $body, int $status, string $key, array $extra) {
    $before = CashDeclaration::query()->where('count_id', $this->count)->sole()->toArray();

    $response = $this->f->cashPut($this->f->cashier, $this->count, $body)->assertStatus($status)->assertJsonPath('message_key', $key);
    foreach ($extra as $field => $value) {
        $response->assertJsonPath($field, $value);
    }
    expect(CashDeclaration::query()->where('count_id', $this->count)->sole()->toArray())->toBe($before);
})->with([
    'no denominations' => [[], 422, 'validation.failed', []],
    'denominations that are not an object' => [['denominations' => 'many'], 422, 'validation.failed', []],
    'an unknown denomination' => [['denominations' => ['20' => 1]], 422, 'shifts.cash.denomination_unknown', ['denomination' => '20']],
    'a negative count' => [['denominations' => ['100' => -1]], 422, 'shifts.cash.count_invalid', ['denomination' => '100']],
    'half a note' => [['denominations' => ['100' => 1.5]], 422, 'shifts.cash.count_invalid', ['denomination' => '100']],
    'a float left larger than the drawer' => [['denominations' => ['100' => 1], 'float_left_total' => '100.01'], 422, 'shifts.cash.float_too_large', []],
    'a reason that does not exist' => [['denominations' => ['100' => 1], 'reason_id' => 999999], 422, 'shifts.reason.unknown', []],
]);

it('takes the closing fields only at a closing: a float left or channel figures on an opening count are refused', function () {
    $this->f->cashPut($this->f->cashier, $this->count, ['denominations' => ['100' => 1], 'float_left_total' => '50.00'])->assertUnprocessable()
        ->assertJsonPath('message_key', 'shifts.cash.count_invalid');
    $this->f->cashPut($this->f->cashier, $this->count, ['denominations' => ['100' => 1], 'channels' => [['code' => 'cash', 'receipts' => 1, 'sum' => '1.00']]])->assertUnprocessable()
        ->assertJsonPath('message_key', 'shifts.cash.count_invalid');
});

it('refuses a count that is not the drawer\'s, and a count that is no longer a draft', function () {
    $stock = $this->f->place($this->f->kitchen);
    $shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->f->stock($this->f->ingredient(), $stock, '10');
    $kitchenCount = (int) $this->f->close($this->f->chef, $shift->id)->json('counts.0.id');
    $this->f->cashPut($this->f->chef, $kitchenCount, ['denominations' => ['100' => 1]])->assertUnprocessable()->assertJsonPath('message_key', 'shifts.cash.no_drawer');

    $this->f->cashPut($this->f->cashier, $this->count, ['denominations' => ['100' => 1]])->assertOk();
    $this->f->submit($this->f->cashier, $this->count)->assertOk();
    $this->f->cashPut($this->f->cashier, $this->count, ['denominations' => ['100' => 9]])->assertStatus(409)->assertJsonPath('message_key', 'shifts.count.not_draft');
    expect(CashDeclaration::query()->where('count_id', $this->count)->sole()->counted_total->amount())->toBe('100.00');
});

it('lets only the people who count at the till declare its cash', function () {
    $body = ['denominations' => ['100' => 1]];

    $this->f->cashPut($this->f->waiter, $this->count, $body)->assertForbidden();
    $this->f->cashPut($this->f->chef, $this->count, $body)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    $this->f->cashPut($this->f->cashier2, $this->count, $body)->assertOk();
    $this->f->cashPut($this->f->manager, $this->count, $body)->assertOk();
    $this->f->cashPut($this->f->admin, $this->count, $body)->assertOk();
});

it('does not see another branch\'s count', function () {
    $other = Branch::factory()->create();
    $shift = ShiftInstance::factory()->open()->create(['branch_id' => $other->id]);
    $foreign = StockCount::factory()->forShift($shift, Location::factory()->create(['branch_id' => $other->id])->id)->create();

    $this->f->cashPut($this->f->admin, $foreign->id, ['denominations' => ['100' => 1]])->assertNotFound();
});

it('answers a repeat with the same key as the first, and writes one audit row', function () {
    $key = (string) Str::uuid();
    $body = ['denominations' => ['100' => 2]];

    $first = $this->f->cashPut($this->f->cashier, $this->count, $body, ['Idempotency-Key' => $key])->assertOk();
    $again = $this->f->cashPut($this->f->cashier, $this->count, $body, ['Idempotency-Key' => $key])->assertOk();

    expect($again->json())->toBe($first->json())
        ->and(AuditLog::query()->where('action', 'count.cash')->count())->toBe(1);
    $audit = AuditLog::query()->where('action', 'count.cash')->sole();
    expect($audit->subject_id)->toBe($this->count)->and($audit->actor_id)->toBe($this->f->cashier->id);
});

it('shows the till widget the cash in the drawer so far, live', function () {
    $this->f->cashPut($this->f->cashier, $this->count, ['denominations' => ['100' => 5]])->assertOk();
    $this->f->submit($this->f->cashier, $this->count)->assertOk();
    $this->f->taken($this->shift, '60.00', '100.00');
    $this->f->taken($this->shift, '40.00');
    $this->f->taken($this->shift, '25.00', null, 'telebirr');

    $this->f->cashRead($this->f->cashier, $this->shift)->assertOk()
        ->assertJsonPath('opening_total', '500.00')
        ->assertJsonPath('carried_float_total', null)
        ->assertJsonPath('cash_sales_total', '100.00')
        ->assertJsonPath('expected_total', '600.00')
        ->assertJsonPath('currency', 'ETB')
        ->assertJsonPath('by_channel.0.code', 'cash')
        ->assertJsonPath('by_channel.0.receipts', 2)
        ->assertJsonPath('by_channel.0.sum', '100.00')
        ->assertJsonPath('by_channel.1.code', 'telebirr')
        ->assertJsonPath('by_channel.1.sum', '25.00')
        ->assertJsonStructure(['by_channel' => [['code', 'name_en', 'name_am', 'receipts', 'sum']]]);

    $this->f->taken($this->shift, '10.50');
    $this->f->cashRead($this->f->waiter, $this->shift)->assertOk()->assertJsonPath('cash_sales_total', '110.50')->assertJsonPath('expected_total', '610.50');
});

it('reads the opening figure as the float carried before the opening count is in', function () {
    $this->f->cashRead($this->f->cashier, $this->shift)->assertOk()
        ->assertJsonPath('opening_total', '0.00')
        ->assertJsonPath('cash_sales_total', '0.00')
        ->assertJsonPath('expected_total', '0.00')
        ->assertJsonPath('by_channel', []);
});

it('refuses the cash of a shift to people who do not work at the till, and of a station with no drawer', function () {
    $this->f->cashRead($this->f->chef, $this->shift)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    $kitchen = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->f->cashRead($this->f->chef, $kitchen->id)->assertUnprocessable()->assertJsonPath('message_key', 'shifts.cash.no_drawer');
    $this->f->cashRead($this->f->admin, 999999)->assertNotFound();
});
