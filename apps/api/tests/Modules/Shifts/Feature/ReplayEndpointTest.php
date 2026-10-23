<?php

declare(strict_types=1);

use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Orders\Models\Order;
use App\Modules\Shifts\Contracts\HashChain;
use App\Modules\Shifts\Contracts\Replay;
use App\Modules\Shifts\Enums\CountDecision;
use App\Modules\Shifts\Enums\CountStatus;
use App\Modules\Shifts\Models\StockCount;
use App\Shared\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\OrderFloor;
use Tests\Support\ShiftsFixture;

pest()->group('module:shifts', 'feature');

/*
 * GET /replay?station_id&at[&nonzero=0] and Contracts\Replay::at (phase-3 plan section 4): the state of a station at a point in time.
 * shifts.replay (admin, manager). The timeline of these tests, Monday 2026-11-02, UTC (local time is three hours later):
 *   06:00 flour +1000 at the kitchen        06:05 shift A starts (chef on duty from 06:05)       07:00 flour -200 (waste), cashier2 joins
 *   08:00 sugar +50, 08:30 sugar -50        09:00 cashier2 leaves; the closing count is started   09:30 closing submitted, shift A leaves the station
 *   09:45 the admin approves it             10:00 it is locked (chain link 1)                     10:30 shift B starts
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ShiftsFixture::build($this->branch, $this);
    $this->t = fn (string $hm): CarbonImmutable => CarbonImmutable::parse("2026-11-02 {$hm}:00", 'UTC');
    $this->go = fn (string $hm) => $this->travelTo(($this->t)($hm));
    $this->place = $this->f->place($this->f->kitchen);
    $this->flour = $this->f->ingredient();
    $this->sugar = $this->f->ingredient();

    ($this->go)('06:00');
    $this->f->stock($this->flour, $this->place, '1000');
    ($this->go)('07:00');
    $this->f->stock($this->flour, $this->place, '-200', MovementReason::Waste);
    ($this->go)('08:00');
    $this->f->stock($this->sugar, $this->place, '50');
    ($this->go)('08:30');
    $this->f->stock($this->sugar, $this->place, '-50', MovementReason::Waste);

    $this->a = $this->f->running($this->f->kitchen, $this->f->chef, [
        'date' => '2026-11-02', 'started_at' => ($this->t)('06:05'), 'opened_at' => ($this->t)('06:05'),
        'closing_started_at' => ($this->t)('09:00'), 'submitted_at' => ($this->t)('09:30'),
    ], 'pendingApproval');
    DB::table('shift_roster')->where('shift_instance_id', $this->a->id)->update(['joined_at' => ($this->t)('06:05')]);
    $this->f->onRoster($this->a, $this->f->cashier2);
    DB::table('shift_roster')->where('shift_instance_id', $this->a->id)->where('user_id', $this->f->cashier2->id)->update(['joined_at' => ($this->t)('07:00'), 'left_at' => ($this->t)('09:00')]);

    $this->closing = StockCount::factory()->forShift($this->a, $this->place->id)->closing()->status(CountStatus::Approved)->create([
        'started_at' => ($this->t)('09:00'), 'submitted_at' => ($this->t)('09:30'), 'decision' => CountDecision::Approved,
        'decided_by_user_id' => $this->f->admin->id, 'decided_at' => ($this->t)('09:45'),
        'counted_by_user_id' => $this->f->chef->id, 'created_by_user_id' => $this->f->chef->id,
    ]);
    ($this->go)('10:00');
    DB::transaction(fn () => app(HashChain::class)->lock($this->closing, $this->f->admin->id));
    $this->closing->refresh();

    ($this->go)('10:30');
    $this->b = $this->f->running($this->f->kitchen, $this->f->manager, ['started_at' => ($this->t)('10:30'), 'date' => '2026-11-02', 'sequence' => 2], 'opening');
    ($this->go)('12:00');

    $this->at = fn (string $hm, array $extra = []) => $this->f->replay($this->f->admin, ['station_id' => $this->f->kitchen->id, 'at' => ($this->t)($hm)->toIso8601ZuluString()] + $extra);
    $this->balances = fn ($response): array => collect($response->json('locations.0.balances'))->mapWithKeys(fn ($b) => [$b['item']['id'] => [$b['on_hand'], $b['reserved']]])->all();
});

it('is empty before anything happened: no shift, nobody on duty, no balances, no counts', function () {
    $response = ($this->at)('05:59')->assertOk();

    $response->assertJsonPath('station.id', $this->f->kitchen->id)
        ->assertJsonPath('shift', null)
        ->assertJsonPath('on_duty', [])
        ->assertJsonPath('open_orders', [])
        ->assertJsonPath('counts', [])
        ->assertJsonPath('locations.0.location.id', $this->place->id)
        ->assertJsonPath('locations.0.balances', [])
        ->assertJsonPath('locations.0.last_locked', null);
});

it('replays the balances as the ledger stood, the shift and the people on it', function () {
    $response = ($this->at)('06:30')->assertOk();

    $response->assertJsonPath('shift.id', $this->a->id)
        ->assertJsonPath('shift.station.id', $this->f->kitchen->id)
        ->assertJsonCount(1, 'on_duty')
        ->assertJsonPath('on_duty.0.user.id', $this->f->chef->id)
        ->assertJsonPath('on_duty.0.user.name', $this->f->chef->name)
        ->assertJsonPath('on_duty.0.left_at', null);
    expect(CarbonImmutable::parse($response->json('on_duty.0.joined_at'))->equalTo(($this->t)('06:05')))->toBeTrue()
        ->and(($this->balances)($response))->toBe([$this->flour->id => ['1000.0000', '0.0000']])
        ->and($response->json('locations.0.balances.0.item.name_en'))->toBe($this->flour->name_en);

    $later = ($this->at)('07:30')->assertOk();
    expect(($this->balances)($later))->toBe([$this->flour->id => ['800.0000', '0.0000']])
        ->and(array_column($later->json('on_duty'), 'user'))->toHaveCount(2)
        ->and(array_map(fn ($u) => $u['id'], array_column($later->json('on_duty'), 'user')))->toBe([$this->f->chef->id, $this->f->cashier2->id]);
});

it('shows a person who left only until they left, and left_at only once it has happened', function () {
    $during = ($this->at)('08:59')->assertOk();
    $after = ($this->at)('09:15')->assertOk();

    expect(array_map(fn ($r) => $r['user']['id'], $during->json('on_duty')))->toContain($this->f->cashier2->id)
        ->and(collect($during->json('on_duty'))->firstWhere('user.id', $this->f->cashier2->id)['left_at'])->toBeNull()
        ->and(array_map(fn ($r) => $r['user']['id'], $after->json('on_duty')))->toBe([$this->f->chef->id]);
});

it('leaves out balances that are zero unless nonzero=0 asks for every item that ever moved', function () {
    $default = ($this->at)('09:00')->assertOk();
    $all = ($this->at)('09:00', ['nonzero' => 0])->assertOk();

    expect(array_keys(($this->balances)($default)))->toBe([$this->flour->id])
        ->and(($this->balances)($all))->toBe([$this->flour->id => ['800.0000', '0.0000'], $this->sugar->id => ['0.0000', '0.0000']]);
    expect(array_keys(($this->balances)(($this->at)('08:15'))))->toBe([$this->flour->id, $this->sugar->id]);
});

it('walks a count through its states by its timestamps, and hides what had not happened yet', function () {
    $status = fn (string $hm) => collect(($this->at)($hm)->assertOk()->json('counts'))->firstWhere('count_id', $this->closing->id);

    expect($status('08:59'))->toBeNull()
        ->and($status('09:10')['status_at'])->toBe('draft')
        ->and($status('09:10')['submitted_at'])->toBeNull()
        ->and($status('09:40')['status_at'])->toBe('pending_approval')
        ->and($status('09:40')['decided_at'])->toBeNull()
        ->and($status('09:50')['status_at'])->toBe('approved')
        ->and($status('09:50')['locked_at'])->toBeNull()
        ->and($status('10:00')['status_at'])->toBe('locked')
        ->and($status('10:00')['type'])->toBe('closing');
    $locked = $status('11:00');
    expect(CarbonImmutable::parse($locked['submitted_at'])->equalTo(($this->t)('09:30')))->toBeTrue()
        ->and(CarbonImmutable::parse($locked['decided_at'])->equalTo(($this->t)('09:45')))->toBeTrue()
        ->and($locked['locked_at'])->not->toBeNull();
});

it('names the last locked link of a place only from the moment it was locked', function () {
    ($this->at)('09:59')->assertOk()->assertJsonPath('locations.0.last_locked', null);

    $response = ($this->at)('10:01')->assertOk();
    $response->assertJsonPath('locations.0.last_locked.count_id', $this->closing->id)
        ->assertJsonPath('locations.0.last_locked.chain_seq', 1)
        ->assertJsonPath('locations.0.last_locked.payload_hash', $this->closing->payload_hash);
    expect($response->json('locations.0.last_locked.locked_at'))->toMatch('/^2026-11-02T10:00:00/');
});

it('says no shift held the station while the closing waited, but still lists the count that waited', function () {
    $response = ($this->at)('09:40')->assertOk();

    $response->assertJsonPath('shift', null)->assertJsonPath('on_duty', []);
    expect(array_column($response->json('counts'), 'count_id'))->toBe([$this->closing->id]);
    $b = ($this->at)('11:00')->assertOk();
    $b->assertJsonPath('shift.id', $this->b->id);
});

it('reads a bare time as the branch\'s local time and an offset as the offset', function () {
    $zulu = ($this->at)('06:30')->assertOk();
    $bare = $this->f->replay($this->f->admin, ['station_id' => $this->f->kitchen->id, 'at' => '2026-11-02T09:30:00'])->assertOk();
    $offset = $this->f->replay($this->f->admin, ['station_id' => $this->f->kitchen->id, 'at' => '2026-11-02T09:30:00+03:00'])->assertOk();

    expect($bare->json('at'))->toBe($zulu->json('at'))->and($offset->json('at'))->toBe($zulu->json('at'))
        ->and(($this->balances)($bare))->toBe(($this->balances)($zulu));
    expect(CarbonImmutable::parse($zulu->json('at'))->equalTo(($this->t)('06:30')))->toBeTrue();
});

it('refuses a future time and a malformed request', function () {
    $this->f->replay($this->f->admin, ['station_id' => $this->f->kitchen->id, 'at' => ($this->t)('12:00')->addMinutes(5)->toIso8601ZuluString()])
        ->assertStatus(422)->assertJsonPath('message_key', 'shifts.replay.future');
    $this->f->replay($this->f->admin, ['at' => ($this->t)('09:00')->toIso8601ZuluString()])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->f->replay($this->f->admin, ['station_id' => $this->f->kitchen->id])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->f->replay($this->f->admin, ['station_id' => $this->f->kitchen->id, 'at' => 'yesterday-ish'])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->f->replay($this->f->admin, ['station_id' => 99999999, 'at' => ($this->t)('09:00')->toIso8601ZuluString()])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->f->replay($this->f->admin, ['station_id' => $this->f->kitchen->id, 'at' => ($this->t)('09:00')->toIso8601ZuluString(), 'nonzero' => 'maybe'])->assertStatus(422);
});

it('is for the admin and the manager only', function () {
    $query = ['station_id' => $this->f->kitchen->id, 'at' => ($this->t)('09:00')->toIso8601ZuluString()];
    foreach ([$this->f->cashier, $this->f->chef, $this->f->waiter, $this->f->baker, $this->f->breadBaker] as $who) {
        $this->f->replay($who, $query)->assertForbidden()->assertJsonPath('message_key', 'auth.forbidden');
    }
    $this->f->replay($this->f->manager, $query)->assertOk();
    $this->f->replay($this->f->admin, $query)->assertOk();
});

it('does not see another branch\'s station', function () {
    $other = ShiftsFixture::build(actInBranch(Branch::factory()->create()), $this);
    $theirs = $other->kitchen->id;
    actInBranch($this->branch);

    $this->f->replay($this->f->admin, ['station_id' => $theirs, 'at' => ($this->t)('09:00')->toIso8601ZuluString()])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
});

it('replays a till without cash: the drawer is not a place with balances, its count is in the list, and no cash block exists', function () {
    $till = $this->f->till;
    $drawer = $this->f->drawer($till);
    ($this->go)('13:00');
    $opened = $this->f->openTill($till, $this->f->cashier, ['100' => 2]);
    $opened['submit']->assertOk();
    ($this->go)('14:00');

    $response = $this->f->replay($this->f->admin, ['station_id' => $till->id, 'at' => ($this->t)('13:30')->toIso8601ZuluString()])->assertOk();

    expect(array_column(array_column($response->json('locations'), 'location'), 'id'))->not->toContain($drawer->id)
        ->and(array_column($response->json('counts'), 'count_id'))->toContain($opened['count'])
        ->and($response->json())->not->toHaveKey('cash')
        ->and($response->json('shift.id'))->toBe($opened['shift']);
});

it('is the same answer through the contract', function () {
    $view = app(Replay::class)->at($this->f->kitchen, ($this->t)('07:30'));

    expect($view->shift?->id)->toBe($this->a->id)
        ->and($view->stationId)->toBe($this->f->kitchen->id)
        ->and($view->at->equalTo(($this->t)('07:30')))->toBeTrue()
        ->and(array_map(fn ($p) => $p->userId, $view->onDuty))->toBe([$this->f->chef->id, $this->f->cashier2->id]);
});

it('replays the orders that were open, from the order timeline', function () {
    $floor = OrderFloor::open($this);
    $m = $floor->menu;
    $this->travelTo(CarbonImmutable::parse('2026-11-03 06:00:00', 'UTC'));
    $order = $floor->send([$m->line($m->sandwich, '2')]);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 06:30:00', 'UTC'));
    $floor->act('accept', $floor->lineOf($order, $m->sandwich)['id'], $floor->chef)->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00', 'UTC'));

    $ask = fn (string $hm) => $this->f->replay($floor->admin, ['station_id' => $m->kitchenStation->id, 'at' => "2026-11-03T{$hm}:00Z"]);

    $ask('05:59')->assertOk()->assertJsonPath('open_orders', []);
    $open = $ask('06:15')->assertOk();
    $open->assertJsonCount(1, 'open_orders')
        ->assertJsonPath('open_orders.0.order_id', $order['id'])
        ->assertJsonPath('open_orders.0.order_no', $order['order_no'])
        ->assertJsonPath('open_orders.0.type', 'takeaway')
        ->assertJsonPath('open_orders.0.table_code', null)
        ->assertJsonPath('open_orders.0.owner.id', $floor->waiter->id)
        ->assertJsonPath('open_orders.0.lines.0.item_id', $m->sandwich->id)
        ->assertJsonPath('open_orders.0.lines.0.qty', '2.0000')
        ->assertJsonPath('open_orders.0.lines.0.status', 'queued');
    expect($open->json('open_orders.0.sent_at'))->toMatch('/^2026-11-03T06:00:00/');
    $ask('06:45')->assertOk()->assertJsonPath('open_orders.0.lines.0.status', 'accepted');
    expect(Order::query()->count())->toBe(1);
});
