<?php

declare(strict_types=1);

use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ChainFixture;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * GET /stations/{station}/handover (phase-3 plan section 4): the Start shift screen's first panel, what the outgoing shift leaves for the
 * incoming one. {previous: ShiftSummary or null, notes: [Note], previous_closing: [{location, count_id, chain_seq, payload_hash,
 * locked_at, provisional, float_left_total}], pending_counts: [CountSummary]}. shifts.view at the station; a station that does not track
 * shifts is 422 shifts.station.not_tracked. A drawer closing carries the float that stays for the next shift (CashDeclarations::carriedFloat);
 * a closing submitted but not locked is `provisional` and carries no hash.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->chain = new ChainFixture($this->f);
    $this->note = function (int $shiftId, array $over = []): int {
        return DB::table('handover_notes')->insertGetId($over + [
            'branch_id' => $this->branch->id, 'client_uuid' => (string) Str::uuid(), 'shift_instance_id' => $shiftId,
            'from_user_id' => $this->f->chef->id, 'to_user_id' => null, 'body' => 'A note', 'created_by_user_id' => $this->f->chef->id,
            'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s.u'),
        ]);
    };
});

it('is empty for a station that has never had a shift', function () {
    $this->f->handover($this->f->chef, $this->f->kitchen->id)->assertOk()
        ->assertJsonPath('previous', null)
        ->assertJsonPath('notes', [])
        ->assertJsonPath('previous_closing', [])
        ->assertJsonPath('pending_counts', []);
});

it('names the previous shift as a summary, the locked closing it left and its hash', function () {
    $link = $this->chain->link($this->place);

    $response = $this->f->handover($this->f->chef, $this->f->kitchen->id)->assertOk();

    $response->assertJsonPath('previous.id', $link->shift_instance_id)
        ->assertJsonPath('previous.status', 'locked')
        ->assertJsonPath('previous.station.id', $this->f->kitchen->id)
        ->assertJsonPath('previous_closing.0.location.id', $this->place->id)
        ->assertJsonPath('previous_closing.0.count_id', $link->id)
        ->assertJsonPath('previous_closing.0.chain_seq', 1)
        ->assertJsonPath('previous_closing.0.payload_hash', $link->payload_hash)
        ->assertJsonPath('previous_closing.0.provisional', false)
        ->assertJsonPath('previous_closing.0.float_left_total', null)
        ->assertJsonPath('pending_counts', []);
    expect($response->json('previous_closing.0.locked_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T/')
        ->and($response->json('previous'))->not->toHaveKeys(['roster', 'counts', 'notes_count']);
});

it('takes the newest closing of a place, and the newest shift as previous', function () {
    $this->chain->link($this->place);
    $this->travelTo(CarbonImmutable::now()->addDay());
    $second = $this->chain->link($this->place);

    $this->f->handover($this->f->chef, $this->f->kitchen->id)->assertOk()
        ->assertJsonPath('previous.id', $second->shift_instance_id)
        ->assertJsonCount(1, 'previous_closing')
        ->assertJsonPath('previous_closing.0.chain_seq', 2);
});

it('does not count the shift that runs now as the previous one', function () {
    $link = $this->chain->link($this->place);
    $this->travelTo(CarbonImmutable::now()->addDay());
    $running = $this->f->running($this->f->kitchen, $this->f->chef);

    $this->f->handover($this->f->chef, $this->f->kitchen->id)->assertOk()
        ->assertJsonPath('previous.id', $link->shift_instance_id)
        ->assertJsonMissing(['id' => $running->id]);
});

it('carries the previous shift\'s notes, and notes addressed to the caller within a week, and no one else\'s', function () {
    $link = $this->chain->link($this->place);
    $previous = $link->shift_instance_id;
    $mine = ($this->note)($previous, ['to_user_id' => $this->f->manager->id, 'body' => 'For the manager']);
    $general = ($this->note)($previous, ['body' => 'For everybody']);
    // an older shift of the station: only what is addressed to the caller, and not older than a week
    $older = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-10-30', 'started_at' => '2026-10-30 06:00:00'], 'locked');
    $toMe = ($this->note)($older->id, ['to_user_id' => $this->f->manager->id, 'body' => 'Older, for the manager', 'created_at' => '2026-10-31 20:00:00.000000']);
    $toSomeone = ($this->note)($older->id, ['to_user_id' => $this->f->admin->id, 'body' => 'Older, for the admin', 'created_at' => '2026-10-31 20:00:00.000000']);
    $unaddressed = ($this->note)($older->id, ['body' => 'Older, for nobody', 'created_at' => '2026-10-31 20:00:00.000000']);
    $stale = ($this->note)($older->id, ['to_user_id' => $this->f->manager->id, 'body' => 'Too old', 'created_at' => '2026-10-10 20:00:00.000000']);

    $forManager = $this->f->handover($this->f->manager, $this->f->kitchen->id)->assertOk();
    $forChef = $this->f->handover($this->f->chef, $this->f->kitchen->id)->assertOk();

    expect(array_column($forManager->json('notes'), 'id'))->toBe([$toMe, $mine, $general])
        ->and(array_column($forChef->json('notes'), 'id'))->toBe([$mine, $general])
        ->and($forManager->json('notes.1'))->toHaveKeys(['id', 'client_uuid', 'shift_id', 'from', 'to', 'body', 'created_at'])
        ->and($forManager->json('notes.1.to.id'))->toBe($this->f->manager->id)
        ->and(array_column($forManager->json('notes'), 'id'))->not->toContain($toSomeone, $unaddressed, $stale);
});

it('lists a closing that waits for a decision as provisional, without a hash, and as a pending count', function () {
    $shift = $this->f->running($this->f->kitchen, $this->f->chef, [], 'pendingApproval');
    $waiting = StockCount::factory()->forShift($shift, $this->place->id)->closing()->status(CountStatus::PendingApproval)
        ->create(['submitted_at' => CarbonImmutable::now(), 'counted_by_user_id' => $this->f->chef->id]);

    $response = $this->f->handover($this->f->manager, $this->f->kitchen->id)->assertOk();

    $response->assertJsonPath('previous.id', $shift->id)
        ->assertJsonPath('previous.status', 'pending_approval')
        ->assertJsonPath('previous_closing.0.count_id', $waiting->id)
        ->assertJsonPath('previous_closing.0.provisional', true)
        ->assertJsonPath('previous_closing.0.chain_seq', null)
        ->assertJsonPath('previous_closing.0.payload_hash', null)
        ->assertJsonPath('previous_closing.0.locked_at', null)
        ->assertJsonCount(1, 'pending_counts')
        ->assertJsonPath('pending_counts.0.id', $waiting->id)
        ->assertJsonPath('pending_counts.0.status', 'pending_approval')
        ->assertJsonPath('pending_counts.0.lines_count', 0);
    expect($response->json('pending_counts.0'))->not->toHaveKey('lines');
});

it('shows the float a locked drawer closing leaves, and a drawer-only station needs no stock place', function () {
    $till = $this->f->till;
    $this->f->drawer($till);
    $opened = $this->f->openTill($till, $this->f->cashier, ['100' => 2]);
    $opened['submit']->assertOk();
    $closing = $this->f->closeTill($opened['shift'], $this->f->cashier);
    $this->f->cashPut($this->f->cashier, $closing['count'], ['denominations' => ['100' => 2], 'float_left_total' => '50.00'])->assertOk();
    $this->f->submit($this->f->cashier, $closing['count'])->assertOk()->assertJsonPath('status', 'locked');

    $response = $this->f->handover($this->f->cashier, $till->id)->assertOk();

    $response->assertJsonCount(1, 'previous_closing')
        ->assertJsonPath('previous_closing.0.location.type', 'cash_drawer')
        ->assertJsonPath('previous_closing.0.count_id', $closing['count'])
        ->assertJsonPath('previous_closing.0.provisional', false)
        ->assertJsonPath('previous_closing.0.float_left_total', '50.00')
        ->assertJsonPath('previous_closing.0.chain_seq', 2);
});

it('marks a drawer closing that waits for approval as provisional, with the float it proposes but no hash', function () {
    $till = $this->f->till;
    $this->f->drawer($till);
    $opened = $this->f->openTill($till, $this->f->cashier, ['100' => 2]);
    $opened['submit']->assertOk();
    $closing = $this->f->closeTill($opened['shift'], $this->f->cashier);
    $this->f->cashPut($this->f->cashier, $closing['count'], [
        'denominations' => ['100' => 1, '50' => 1], 'float_left_total' => '50.00', 'reason_id' => $this->f->reasonId('miscount'),
    ])->assertOk();
    $this->f->submit($this->f->cashier, $closing['count'])->assertOk()->assertJsonPath('status', 'pending_approval');

    $this->f->handover($this->f->cashier, $till->id)->assertOk()
        ->assertJsonPath('previous.status', 'pending_approval')
        ->assertJsonPath('previous_closing.0.count_id', $closing['count'])
        ->assertJsonPath('previous_closing.0.provisional', true)
        ->assertJsonPath('previous_closing.0.payload_hash', null)
        ->assertJsonPath('previous_closing.0.float_left_total', '50.00')
        ->assertJsonPath('pending_counts.0.id', $closing['count']);
});

it('is for those who read the station: the floor there, the managers anywhere', function () {
    $this->f->handover($this->f->cashier, $this->f->kitchen->id)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    $this->f->handover($this->f->chef, $this->f->till->id)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    $this->f->handover($this->f->waiter, $this->f->till->id)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    $this->f->handover($this->f->cashier, $this->f->till->id)->assertOk();
    $this->f->handover($this->f->chef, $this->f->kitchen->id)->assertOk();
    $this->f->handover($this->f->manager, $this->f->kitchen->id)->assertOk();
    $this->f->handover($this->f->admin, $this->f->cakeRoom->id)->assertOk();
});

it('refuses a station that does not track shifts, and a station of another branch is a 404', function () {
    $this->f->handover($this->f->admin, $this->f->store->id)->assertStatus(422)->assertJsonPath('message_key', 'shifts.station.not_tracked');
    $other = ShiftsFixture::build(actInBranch(Branch::factory()->create()), $this);
    $theirs = $other->kitchen->id;
    actInBranch($this->branch);

    $this->f->handover($this->f->admin, $theirs)->assertNotFound();
});
