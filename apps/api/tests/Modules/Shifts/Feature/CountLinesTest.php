<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Shifts\Models\StockCount;
use App\Modules\Shifts\Models\VarianceReason;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * PUT /stock-counts/{count}/lines (step 3.3a; phase-3 plan section 4): the counter touches the lines of a draft. A counted figure,
 * `confirm` (the line looked at, at its prefill), `confirm_all` (the explicit "nothing changed": carried for an opening, the live
 * ledger figure for a closing), a reason and a note; an item not on the count adds a line (expected 0 until submit). Quantities
 * are decimal strings in the item's base unit, never negative, at most four decimals and ten integer digits. Draft only.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->sugar = $this->f->ingredient();
    $this->f->stock($this->flour, $this->place, '10000');
    $this->f->stock($this->sugar, $this->place, '250');
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->count = (int) $this->f->close($this->f->chef, $this->shift->id)->assertOk()->json('counts.0.id');
    $this->line = fn (array $json, Item $item): array => collect($json['lines'])->firstWhere('item.id', $item->id);
});

it('marks a line looked at with `confirm`, at the live ledger figure on a closing count', function () {
    $response = $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $this->flour->id, 'confirm' => true]]])->assertOk();

    $flour = ($this->line)($response->json(), $this->flour);
    $sugar = ($this->line)($response->json(), $this->sugar);
    expect($flour['counted_qty'])->toBe('10000.0000')->and($flour['confirmed'])->toBeTrue()
        ->and($sugar['counted_qty'])->toBeNull()->and($sugar['confirmed'])->toBeFalse();
});

it('takes a counted figure as a touch: it confirms the line', function () {
    $response = $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $this->sugar->id, 'counted_qty' => '249.5']]])->assertOk();

    $sugar = ($this->line)($response->json(), $this->sugar);
    expect($sugar['counted_qty'])->toBe('249.5000')->and($sugar['confirmed'])->toBeTrue();
});

it('un-confirms a line with confirm false and clears a figure with null', function () {
    $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $this->sugar->id, 'counted_qty' => '249.5']]])->assertOk();

    $unconfirmed = $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $this->sugar->id, 'confirm' => false]]])->assertOk();
    $sugar = ($this->line)($unconfirmed->json(), $this->sugar);
    expect($sugar['counted_qty'])->toBe('249.5000')->and($sugar['confirmed'])->toBeFalse();

    $cleared = $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $this->sugar->id, 'counted_qty' => null]]])->assertOk();
    $sugar = ($this->line)($cleared->json(), $this->sugar);
    expect($sugar['counted_qty'])->toBeNull()->and($sugar['confirmed'])->toBeFalse();
});

it('confirms every line at its prefill with confirm_all, then lets one line be corrected in the same request', function () {
    $response = $this->f->lines($this->f->chef, $this->count, [
        'confirm_all' => true,
        'lines' => [['item_id' => $this->sugar->id, 'counted_qty' => '200', 'reason_id' => $this->f->reasonId('spoilage')]],
    ])->assertOk();

    $flour = ($this->line)($response->json(), $this->flour);
    $sugar = ($this->line)($response->json(), $this->sugar);
    expect($flour['counted_qty'])->toBe('10000.0000')->and($flour['confirmed'])->toBeTrue()
        ->and($sugar['counted_qty'])->toBe('200.0000')->and($sugar['reason']['code'])->toBe('spoilage');
});

it('leaves a line that was already entered alone when confirm_all runs', function () {
    $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9000']]])->assertOk();

    $response = $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true])->assertOk();

    expect(($this->line)($response->json(), $this->flour)['counted_qty'])->toBe('9000.0000')
        ->and(($this->line)($response->json(), $this->sugar)['counted_qty'])->toBe('250.0000');
});

it('adds an item that is not on the count, expected nothing until the submit', function () {
    $found = $this->f->ingredient('pcs');

    $response = $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $found->id, 'counted_qty' => '12']]])->assertOk();

    $line = ($this->line)($response->json(), $found);
    expect($line['counted_qty'])->toBe('12.0000')->and($line['expected_qty'])->toBe('0.0000')->and($line['item']['unit'])->toBe('pcs')->and($line['confirmed'])->toBeTrue();
});

it('keeps a reason and a note on the line', function () {
    $response = $this->f->lines($this->f->chef, $this->count, ['lines' => [
        ['item_id' => $this->flour->id, 'counted_qty' => '9000', 'reason_id' => $this->f->reasonId('other'), 'reason_note' => 'Sack split'],
    ]])->assertOk();

    $flour = ($this->line)($response->json(), $this->flour);
    expect($flour['reason']['code'])->toBe('other')->and($flour['reason']['name_am'])->not->toBeEmpty()->and($flour['note'])->toBe('Sack split');
});

it('refuses a figure that is not a quantity: negative, too many decimals, too many digits, not a number', function (mixed $bad) {
    $this->f->lines($this->f->chef, $this->count, ['lines' => [
        ['item_id' => $this->sugar->id, 'counted_qty' => '5'],
        ['item_id' => $this->flour->id, 'counted_qty' => $bad],
    ]])->assertStatus(422)
        ->assertJsonPath('message_key', 'shifts.count.qty_invalid')
        ->assertJsonPath('item_id', $this->flour->id);

    // all or nothing: the valid line of the same request was not kept
    expect(StockCount::query()->findOrFail($this->count)->lines()->whereNotNull('counted_qty')->count())->toBe(0);
})->with(['-1', '1.23456', '12345678901', 'abc', '1e3', '0x10', '1,5', '--1', 12.5, true, [['1']]]);

it('accepts the extremes of the column and a whole number sent as a JSON integer', function () {
    $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '9999999999.9999']]])->assertOk();
    $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '0']]])->assertOk();
    $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => 12]]])->assertOk()
        ->assertJsonPath('lines.0.counted_qty', '12.0000');
});

it('refuses an item the branch cannot stock, naming it', function (string $kind) {
    $other = Branch::factory()->create();
    $item = match ($kind) {
        'another branch' => Item::factory()->ingredient('g')->create(['branch_id' => $other->id, 'created_by_user_id' => makeUser($other, 'admin')->id]),
        'made to order' => Item::factory()->product(Fulfilment::ToOrder)->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]),
        'switched off' => $this->f->ingredient('g', ['is_active' => false]),
    };

    $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $item->id, 'counted_qty' => '1']]])->assertStatus(422)
        ->assertJsonPath('message_key', 'shifts.count.item_unknown')
        ->assertJsonPath('item_id', $item->id);
})->with(['another branch', 'made to order', 'switched off']);

it('refuses an item id that does not exist', function () {
    $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => 987654, 'counted_qty' => '1']]])->assertStatus(422)
        ->assertJsonPath('message_key', 'shifts.count.item_unknown');
});

it('accepts a product kept in stock or bought in, as a found item', function () {
    $cake = Item::factory()->product(Fulfilment::ToStock)->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);
    $water = Item::factory()->product(Fulfilment::BoughtIn)->create(['branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id]);

    $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $cake->id, 'counted_qty' => '2'], ['item_id' => $water->id, 'counted_qty' => '6']]])->assertOk();
});

it('refuses a reason that does not exist, is another branch\'s or is switched off', function (string $kind) {
    $other = Branch::factory()->create();
    $id = match ($kind) {
        'unknown' => 987654,
        'another branch' => (int) VarianceReason::query()->withoutGlobalScopes()->where('branch_id', $other->id)->value('id'),
        'switched off' => (function (): int {
            $reason = VarianceReason::query()->where('code', 'breakage')->firstOrFail();
            $reason->forceFill(['is_active' => false])->save();

            return $reason->id;
        })(),
    };

    $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '1', 'reason_id' => $id]]])->assertStatus(422)
        ->assertJsonPath('message_key', 'shifts.reason.unknown')
        ->assertJsonPath('reason_id', $id);
})->with(['unknown', 'another branch', 'switched off']);

it('validates the body: lines or confirm_all, the note length, the item id', function () {
    $this->f->lines($this->f->chef, $this->count, [])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->f->lines($this->f->chef, $this->count, ['lines' => [['counted_qty' => '1']]])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $this->flour->id, 'reason_note' => str_repeat('x', 256)]]])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->f->lines($this->f->chef, $this->count, ['lines' => 'all'])->assertStatus(422);
});

it('refuses to change a count that is no longer a draft, naming the status', function () {
    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true])->assertOk();
    $this->f->submit($this->f->chef, $this->count)->assertOk();

    $this->f->lines($this->f->chef, $this->count, ['lines' => [['item_id' => $this->flour->id, 'counted_qty' => '1']]])->assertStatus(409)
        ->assertJsonPath('message_key', 'shifts.count.not_draft')
        ->assertJsonPath('status', 'locked');
});

it('bumps the count\'s version and writes one audit row per change', function () {
    $before = StockCount::query()->findOrFail($this->count)->version;

    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true])->assertOk()->assertJsonPath('version', $before + 1);

    $row = AuditLog::query()->where('action', 'count.lines')->sole();
    expect($row->subject_id)->toBe($this->count)->and($row->actor_id)->toBe($this->f->chef->id);
});

it('replays the same key without writing again', function () {
    $key = ['Idempotency-Key' => (string) Str::uuid()];
    $first = $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true], $key)->assertOk();

    $again = $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true], $key)->assertOk();

    expect($again->json())->toBe($first->json())->and(AuditLog::query()->where('action', 'count.lines')->count())->toBe(1);
});

it('lets only someone who counts at the station change the lines', function () {
    $body = ['confirm_all' => true];

    $this->f->lines($this->f->cashier, $this->count, $body)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    $this->f->lines($this->f->waiter, $this->count, $body)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    $this->f->lines($this->f->admin, $this->count, $body)->assertOk();
    $foreign = makeUser(Branch::factory()->create(), 'admin');
    $this->f->lines($foreign, $this->count, $body)->assertNotFound();
});
