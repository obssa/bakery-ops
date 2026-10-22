<?php

declare(strict_types=1);

use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * The count reads (step 3.3a; phase-3 plan section 4): GET /shifts/{shift}/counts lists every revision of the shift's counts, oldest
 * first, with their lines; GET /stock-counts/{count} is one Count with its lines side by side (expected, carried, counted,
 * variance, reason, note). While a count is a draft its expected figure is the live ledger figure; after the submit it is the
 * figure of the snapshot. Both need shifts.view at the station; another branch's rows are 404; the single count is a polled read
 * (ETag, If-None-Match, 304).
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient('g', ['name_en' => 'Flour', 'name_am' => 'ዱቄት']);
    $this->f->stock($this->flour, $this->place, '10000');
    $this->shift = $this->f->running($this->f->kitchen, $this->f->chef);
    $this->count = (int) $this->f->close($this->f->chef, $this->shift->id)->assertOk()->json('counts.0.id');
    $this->get = fn (string $uri, $as, array $headers = []) => $this->f->send('GET', $uri, $as, [], $headers);
});

it('reads one count with the shape of the plan: the place, the status, the people, the lines side by side', function () {
    $response = ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->chef)->assertOk();

    $response->assertJsonPath('id', $this->count)
        ->assertJsonPath('shift_id', $this->shift->id)
        ->assertJsonPath('location.id', $this->place->id)
        ->assertJsonPath('location.name_en', 'Kitchen')
        ->assertJsonPath('location.type', 'kitchen')
        ->assertJsonPath('type', 'closing')
        ->assertJsonPath('seq', 1)
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('counted_by', null)
        ->assertJsonPath('snapshot_at', null)
        ->assertJsonPath('submitted_at', null)
        ->assertJsonPath('decision', null)
        ->assertJsonPath('supersede_cause', null)
        ->assertJsonPath('superseded_by_id', null)
        ->assertJsonPath('cash', null)
        ->assertJsonPath('chain', null)
        ->assertJsonPath('blocked_by_pending', null)
        ->assertJsonPath('lines.0.item.id', $this->flour->id)
        ->assertJsonPath('lines.0.item.name_en', 'Flour')
        ->assertJsonPath('lines.0.item.name_am', 'ዱቄት')
        ->assertJsonPath('lines.0.item.unit', 'g')
        ->assertJsonPath('lines.0.expected_qty', '10000.0000')
        ->assertJsonPath('lines.0.carried_qty', null)
        ->assertJsonPath('lines.0.counted_qty', null)
        ->assertJsonPath('lines.0.variance_qty', null)
        ->assertJsonPath('lines.0.confirmed', false)
        ->assertJsonPath('lines.0.reason', null)
        ->assertJsonPath('lines.0.note', null)
        ->assertJsonPath('lines.0.reason_status', 'none')
        ->assertJsonPath('lines.0.adjustment_movement_id', null);
    expect($response->json('version'))->toBeInt();
});

it('shows the live ledger figure on a draft and the snapshot figure after the submit', function () {
    $this->f->stock($this->flour, $this->place, '500');
    ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->chef)->assertOk()->assertJsonPath('lines.0.expected_qty', '10500.0000');

    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true])->assertOk();
    $this->f->submit($this->f->chef, $this->count)->assertOk();
    $this->f->stock($this->flour, $this->place, '70'); // after the snapshot: the count does not move

    ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->chef)->assertOk()
        ->assertJsonPath('status', 'locked')
        ->assertJsonPath('lines.0.expected_qty', '10500.0000')
        ->assertJsonPath('lines.0.counted_qty', '10500.0000')
        ->assertJsonPath('lines.0.variance_qty', '0.0000');
});

it('is a conditional read: 304 with the tag, a new tag when the count or the ledger moves', function () {
    $first = ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->chef)->assertOk();
    $tag = $first->headers->get('ETag');
    expect($tag)->not->toBeNull()->and($first->headers->get('Cache-Control'))->toContain('no-cache')->toContain('private');

    ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->chef, ['If-None-Match' => $tag])->assertStatus(304);

    $this->f->lines($this->f->chef, $this->count, ['confirm_all' => true])->assertOk();
    $second = ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->chef, ['If-None-Match' => $tag])->assertOk();
    $tag2 = $second->headers->get('ETag');
    expect($tag2)->not->toBe($tag);

    ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->chef, ['If-None-Match' => $tag2])->assertStatus(304);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:05', 'UTC'));
    $this->f->stock($this->flour, $this->place, '1'); // a late movement changes what a draft expects
    ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->chef, ['If-None-Match' => $tag2])->assertOk();
});

it('lists every revision of a shift\'s counts, oldest first, each with its lines', function () {
    $bar = $this->f->place($this->f->kitchen, LocationType::Bar);
    // a second, earlier opening count of the same shift (a revision) and one at another place
    StockCount::factory()->forShift($this->shift, $this->place->id)->opening()->status(CountStatus::Approved)->create(['started_at' => '2026-11-02 06:00:00']);
    StockCount::factory()->forShift($this->shift, $bar->id)->opening()->status(CountStatus::Approved)->create(['started_at' => '2026-11-02 06:00:01']);

    $response = ($this->get)("/api/v1/shifts/{$this->shift->id}/counts", $this->f->chef)->assertOk();

    expect(array_column($response->json('data'), 'type'))->toBe(['opening', 'opening', 'closing'])
        ->and($response->json('data.2.id'))->toBe($this->count)
        ->and($response->json('data.2.lines.0.item.id'))->toBe($this->flour->id);
});

it('lets the people of the station read, and refuses the rest', function () {
    ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->cashier)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    ($this->get)("/api/v1/shifts/{$this->shift->id}/counts", $this->f->cashier)->assertForbidden()->assertJsonPath('message_key', 'shifts.station.forbidden');
    ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->waiter)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->manager)->assertOk();
    ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->admin)->assertOk();
});

it('does not see another branch\'s count or shift, or one that does not exist', function () {
    $foreign = makeUser(Branch::factory()->create(), 'admin');

    ($this->get)("/api/v1/stock-counts/{$this->count}", $foreign)->assertNotFound();
    ($this->get)("/api/v1/shifts/{$this->shift->id}/counts", $foreign)->assertNotFound();
    ($this->get)('/api/v1/stock-counts/999999', $this->f->admin)->assertNotFound();
});

it('names the unresolved count in the way on a draft at the same place', function () {
    $earlier = $this->f->running($this->f->kitchen, $this->f->chef, ['date' => '2026-11-01'], state: 'locked');
    $waiting = StockCount::factory()->forShift($earlier, $this->place->id)->closing()->status(CountStatus::PendingApproval)->create();

    ($this->get)("/api/v1/stock-counts/{$this->count}", $this->f->chef)->assertOk()->assertJsonPath('blocked_by_pending.count_id', $waiting->id);
    ($this->get)("/api/v1/stock-counts/{$waiting->id}", $this->f->chef)->assertOk()->assertJsonPath('blocked_by_pending', null);
});
