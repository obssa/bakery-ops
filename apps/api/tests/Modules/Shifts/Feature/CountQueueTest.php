<?php

declare(strict_types=1);

use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * GET /stock-counts (step 3.3c; phase-3 plan section 4): the admin's queue of counts waiting for a co-signature or a decision, for the
 * stations the caller works at (every station with stations.all), oldest first, polled with an ETag. A row is a CountSummary plus what an
 * approver needs without opening the count: the station, the shift, who counted, and the lines that differ with the figures and what
 * moved since. Monday 2026-11-02. The kitchen counts in grams and requires an overseer in the second case only.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->kitchenPlace = $this->f->place($this->f->kitchen);
    $this->cakePlace = $this->f->place($this->f->cakeRoom);
    $this->flour = $this->f->ingredient();
    $this->sugar = $this->f->ingredient();
    $this->f->stock($this->flour, $this->kitchenPlace, '10000');
    $this->f->stock($this->sugar, $this->cakePlace, '2000');
    // a count handed in at a station, short by 500 on its one item
    $this->handIn = function ($station, $by, $item, string $counted): int {
        $shift = $this->f->running($station, $by);
        $this->travelTo(CarbonImmutable::now()->addMinutes(10));
        $count = (int) $this->f->close($by, $shift->id)->assertOk()->json('counts.0.id');
        $this->f->lines($by, $count, ['confirm_all' => true, 'lines' => [['item_id' => $item->id, 'counted_qty' => $counted, 'reason_id' => $this->f->reasonId('spoilage')]]])->assertOk();
        $this->f->submit($by, $count)->assertOk();

        return $count;
    };
});

it('lists the counts waiting for a decision with the facts an approver needs', function () {
    $count = ($this->handIn)($this->f->kitchen, $this->f->chef, $this->flour, '9500');
    $this->f->stock($this->flour, $this->kitchenPlace, '200'); // arrived after the count

    $response = $this->f->queue($this->f->admin)->assertOk();

    $response->assertJsonPath('counts.pending_approval', 1)
        ->assertJsonPath('counts.awaiting_cosign', 0)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $count)
        ->assertJsonPath('data.0.status', 'pending_approval')
        ->assertJsonPath('data.0.type', 'closing')
        ->assertJsonPath('data.0.station.id', $this->f->kitchen->id)
        ->assertJsonPath('data.0.station.code', 'kitchen')
        ->assertJsonPath('data.0.shift.date', '2026-11-02')
        ->assertJsonPath('data.0.counted_by.id', $this->f->chef->id)
        ->assertJsonPath('data.0.lines_count', 1)
        ->assertJsonCount(1, 'data.0.variance_lines')
        ->assertJsonPath('data.0.variance_lines.0.item.id', $this->flour->id)
        ->assertJsonPath('data.0.variance_lines.0.expected_qty', '10000.0000')
        ->assertJsonPath('data.0.variance_lines.0.counted_qty', '9500.0000')
        ->assertJsonPath('data.0.variance_lines.0.variance_qty', '-500.0000')
        ->assertJsonPath('data.0.variance_lines.0.moved_since', '200.0000')
        ->assertJsonPath('data.0.variance_lines.0.reason.code', 'spoilage');
    expect($response->json('server_time'))->toBeString();
});

it('leaves out lines that match and counts that are not waiting', function () {
    ($this->handIn)($this->f->kitchen, $this->f->chef, $this->flour, '10000');
    $waiting = ($this->handIn)($this->f->cakeRoom, $this->f->baker, $this->sugar, '1900');

    $response = $this->f->queue($this->f->admin)->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$waiting], 'the matching count went straight to approved')
        ->and($response->json('counts'))->toBe(['awaiting_cosign' => 0, 'pending_approval' => 1]);
});

it('lists counts that wait for a co-signature, oldest first, and filters by status and station', function () {
    $this->f->cakeRoom->forceFill(['requires_overseer' => true])->save();
    $first = ($this->handIn)($this->f->kitchen, $this->f->chef, $this->flour, '9500');
    $second = ($this->handIn)($this->f->cakeRoom, $this->f->baker, $this->sugar, '1900');

    $all = $this->f->queue($this->f->admin)->assertOk();
    expect(collect($all->json('data'))->pluck('id')->all())->toBe([$first, $second])
        ->and($all->json('counts'))->toBe(['awaiting_cosign' => 1, 'pending_approval' => 1]);

    $cosign = $this->f->queue($this->f->admin, ['status' => 'awaiting_cosign'])->assertOk();
    expect(collect($cosign->json('data'))->pluck('id')->all())->toBe([$second])
        ->and($cosign->json('counts'))->toBe(['awaiting_cosign' => 1, 'pending_approval' => 1], 'the totals ignore the status filter');

    $kitchen = $this->f->queue($this->f->admin, ['station_id' => $this->f->kitchen->id])->assertOk();
    expect(collect($kitchen->json('data'))->pluck('id')->all())->toBe([$first]);
});

it('shows a person only the counts of the stations they work at, and an admin all of them', function () {
    $kitchen = ($this->handIn)($this->f->kitchen, $this->f->chef, $this->flour, '9500');
    $cake = ($this->handIn)($this->f->cakeRoom, $this->f->baker, $this->sugar, '1900');

    $chef = $this->f->queue($this->f->chef)->assertOk();
    expect(collect($chef->json('data'))->pluck('id')->all())->toBe([$kitchen])
        ->and($chef->json('counts.pending_approval'))->toBe(1);

    expect(collect($this->f->queue($this->f->manager)->json('data'))->pluck('id')->all())->toBe([$kitchen, $cake]);
    $this->f->queue($this->f->chef, ['station_id' => $this->f->cakeRoom->id])->assertOk()->assertJsonCount(0, 'data');
});

it('keeps mine to the counts the caller handed in', function () {
    $kitchen = ($this->handIn)($this->f->kitchen, $this->f->chef, $this->flour, '9500');
    ($this->handIn)($this->f->cakeRoom, $this->f->baker, $this->sugar, '1900');

    $mine = $this->f->queue($this->f->admin, ['mine' => 1])->assertOk();
    expect($mine->json('data'))->toBe([]);
    $chef = $this->f->queue($this->f->chef, ['mine' => 1])->assertOk();
    expect(collect($chef->json('data'))->pluck('id')->all())->toBe([$kitchen]);
});

it('limits the page, at most 200', function () {
    ($this->handIn)($this->f->kitchen, $this->f->chef, $this->flour, '9500');
    ($this->handIn)($this->f->cakeRoom, $this->f->baker, $this->sugar, '1900');

    $this->f->queue($this->f->admin, ['limit' => 1])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('counts.pending_approval', 2);
    $this->f->queue($this->f->admin, ['limit' => 201])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->f->queue($this->f->admin, ['status' => 'approved'])->assertStatus(422);
});

it('refuses a role without shifts.view', function () {
    $this->f->queue($this->f->waiter)->assertForbidden();
});

it('does not show another branch\'s counts', function () {
    ($this->handIn)($this->f->kitchen, $this->f->chef, $this->flour, '9500');
    $foreign = makeUser(Branch::factory()->create(), 'admin');

    $this->f->queue($foreign)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('counts.pending_approval', 0);
});

it('answers 304 to a matching If-None-Match and a new tag after a count moves', function () {
    $count = ($this->handIn)($this->f->kitchen, $this->f->chef, $this->flour, '9500');
    $first = $this->f->queue($this->f->admin)->assertOk();
    $tag = $first->headers->get('ETag');
    expect($tag)->not->toBeNull();

    $this->f->queue($this->f->admin, [], ['If-None-Match' => $tag])->assertStatus(304);

    $this->travelTo(CarbonImmutable::now()->addMinute());
    $this->f->approve($this->f->manager, $count)->assertOk();
    $after = $this->f->queue($this->f->admin, [], ['If-None-Match' => $tag])->assertOk();
    expect($after->headers->get('ETag'))->not->toBe($tag)
        ->and($after->json('data'))->toBe([]);
});

it('moves the tag on every poll while the newest ledger movement is inside the overlap window (moved_since reads the ledger)', function () {
    ($this->handIn)($this->f->kitchen, $this->f->chef, $this->flour, '9500');
    stockIn($this->flour, $this->kitchenPlace, '10');
    $start = CarbonImmutable::now();

    $this->travelTo($start->addSeconds(1));
    $inside = $this->f->queue($this->f->admin)->assertOk()->headers->get('ETag');
    $this->travelTo($start->addSeconds(2));
    $this->f->queue($this->f->admin, [], ['If-None-Match' => $inside])->assertOk();

    $this->travelTo($start->addSeconds(9));
    $settled = $this->f->queue($this->f->admin)->headers->get('ETag');
    $this->travelTo($start->addSeconds(12));
    $this->f->queue($this->f->admin, [], ['If-None-Match' => $settled])->assertStatus(304);
});

it('moves the tag when a count arrives with an id below the highest one', function () {
    ($this->handIn)($this->f->kitchen, $this->f->chef, $this->flour, '9500');
    $tag = $this->f->queue($this->f->admin)->headers->get('ETag');
    $late = ($this->handIn)($this->f->cakeRoom, $this->f->baker, $this->sugar, '1900');
    DB::table('stock_counts')->where('id', $late)->update(['updated_at' => CarbonImmutable::now()->subDay()]);

    $this->f->queue($this->f->admin, [], ['If-None-Match' => $tag])->assertOk();
    expect(StockCount::query()->count())->toBeGreaterThan(1);
});
