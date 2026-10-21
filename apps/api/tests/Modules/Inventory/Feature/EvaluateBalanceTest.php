<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Contracts\Reservations;
use App\Modules\Inventory\Contracts\ReserveLine;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Jobs\EvaluateBalance;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Inventory\Listeners\QueueBalanceEvaluation;
use App\Shared\Actor\ActorContext;
use App\Shared\Enums\StationCode;
use App\Shared\Events\StockChanged;
use App\Shared\Events\StockOut;
use App\Shared\Events\StockRecovered;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Step 2.1 (F1, ADR 0011): the stock alert core. Every StockChanged queues one EvaluateBalance job for its (item,
 * place), unique until it starts; the job reads the CURRENT balance under the row lock, opens a stock_alert_states episode
 * when available has fallen to 0 or below (StockOut), closes it when available is above 0 again (StockRecovered), and does
 * it as the person whose movement caused it. These cases are the module's own; the production->inventory contract
 * (tests/Contract/Production/InventoryContractTest.php) pins what Production relies on. Pest runs the queue `sync`, so the
 * queue pair of plan section 7 is a Queue::fake test (the dispatch) and a sync test (the effect).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    $this->waiter = makeUser($this->branch, 'waiter');
    withActor($this->admin->id);
    $this->tillStation = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $b]);
    $this->display = stockLocation($this->branch, LocationType::DisplayCase, $this->tillStation);
    $this->store = stockLocation($this->branch, LocationType::Store);
    $this->cake = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create(['branch_id' => $b, 'name_en' => 'Cake', 'name_am' => 'ኬክ']);
    $this->flour = Item::factory()->ingredient('g')->create(['branch_id' => $b, 'name_en' => 'Flour', 'name_am' => 'ዱቄት']);
    $this->episodes = fn (Item $item, $at) => DB::table('stock_alert_states')->where('item_id', $item->id)->where('location_id', $at->id)->orderBy('id')->get();
    $this->job = fn (Item $item, $at): EvaluateBalance => new EvaluateBalance($this->branch->id, $item->id, $at->id);
    $this->run = fn (EvaluateBalance $job) => app()->call([$job, 'handle']);
    $this->reserve = fn (Item $item, string $qty, $at): string => tap((string) Str::uuid(), fn (string $ref) => app(Reservations::class)->reserve([new ReserveLine($item->id, $qty, $at->id)], $ref));
});

it('queues one unique EvaluateBalance per changed balance, on the default queue, after commit', function () {
    Queue::fake();

    stockIn($this->cake, $this->display, '5');
    stockIn($this->cake, $this->display, '1');  // the first job has not started: it covers this change too
    stockIn($this->flour, $this->store, '900'); // another balance, another job

    Queue::assertPushed(EvaluateBalance::class, 2);
    $jobs = Queue::pushed(EvaluateBalance::class)->values();
    expect($jobs->map(fn (EvaluateBalance $j): array => [$j->branchId, $j->itemId, $j->locationId])->all())->toBe([
        [$this->branch->id, $this->cake->id, $this->display->id],
        [$this->branch->id, $this->flour->id, $this->store->id],
    ]);
    foreach ($jobs as $job) {
        expect($job)->toBeInstanceOf(ShouldQueue::class)
            ->and($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
            ->and($job->queue)->toBeNull('the default queue: the cron worker on the live host drains only that one')
            ->and($job->connection)->toBeNull()
            ->and($job->afterCommit)->toBeTrue();
    }
    expect($jobs[0]->uniqueId())->not->toBe($jobs[1]->uniqueId())
        ->and(($this->job)($this->cake, $this->display)->uniqueId())->toBe($jobs[0]->uniqueId());
})->group('module:inventory', 'feature');

it('dispatches the job from a listener that is itself synchronous, so a queued listener cannot be made unique by mistake', function () {
    expect(QueueBalanceEvaluation::class)->not->toImplement(ShouldQueue::class);
    Queue::fake();

    app(QueueBalanceEvaluation::class)->handle(new StockChanged($this->branch->id, $this->cake->id, $this->display->id, '0.0000', '0.0000', '0.0000', []));

    Queue::assertPushed(EvaluateBalance::class, 1);
})->group('module:inventory', 'feature');

it('has the whole chain take effect when the queue is synchronous: a sale of the last unit leaves one open episode', function () {
    Event::fake([StockOut::class, StockRecovered::class]);
    stockIn($this->cake, $this->display, '2');

    ($this->reserve)($this->cake, '2', $this->display);

    $episodes = ($this->episodes)($this->cake, $this->display);
    expect($episodes)->toHaveCount(1)
        ->and($episodes[0]->type)->toBe('out')
        ->and($episodes[0]->is_active)->toBe(1)
        ->and($episodes[0]->branch_id)->toBe($this->branch->id)
        ->and($episodes[0]->location_key)->toBe($this->display->id)
        ->and($episodes[0]->caused_by_user_id)->toBe($this->admin->id);
    Event::assertDispatchedTimes(StockOut::class, 1);
})->group('module:inventory', 'feature');

it('is idempotent: running the job again on an unchanged balance opens nothing and announces nothing', function () {
    Event::fake([StockOut::class, StockRecovered::class]);
    stockIn($this->cake, $this->display, '1');
    ($this->reserve)($this->cake, '1', $this->display);

    foreach (range(1, 3) as $_) {
        ($this->run)(($this->job)($this->cake, $this->display));
    }

    expect(($this->episodes)($this->cake, $this->display))->toHaveCount(1);
    Event::assertDispatchedTimes(StockOut::class, 1);
    Event::assertNotDispatched(StockRecovered::class);
})->group('module:inventory', 'feature');

it('treats an oversold balance as out, and recovers only when available is above 0, not at 0', function () {
    Event::fake([StockOut::class, StockRecovered::class]);
    stockIn($this->cake, $this->display, '5');
    ($this->reserve)($this->cake, '3', $this->display);
    stockIn($this->cake, $this->display, '-4', MovementReason::Waste); // 1 on hand, 3 reserved: available -2

    $episodes = ($this->episodes)($this->cake, $this->display);
    expect($episodes)->toHaveCount(1)
        ->and($episodes[0]->available_at_start)->toBe('-2.0000');
    Event::assertDispatchedTimes(StockOut::class, 1);

    stockIn($this->cake, $this->display, '2', MovementReason::ProductionOutput); // available exactly 0
    Event::assertNotDispatched(StockRecovered::class);
    expect(($this->episodes)($this->cake, $this->display)[0]->is_active)->toBe(1);

    stockIn($this->cake, $this->display, '1', MovementReason::ProductionOutput); // available 1
    Event::assertDispatchedTimes(StockRecovered::class, 1);
    $closed = ($this->episodes)($this->cake, $this->display)[0];
    expect($closed->is_active)->toBeNull()
        ->and($closed->cleared_at)->not->toBeNull();
})->group('module:inventory', 'feature');

it('names the item and the place in both languages and says what kind of item it is, for an ingredient too', function () {
    Event::fake([StockOut::class, StockRecovered::class]);
    stockIn($this->flour, $this->store, '100');

    stockIn($this->flour, $this->store, '-100', MovementReason::Waste);

    Event::assertDispatchedTimes(StockOut::class, 1);
    $event = Event::dispatched(StockOut::class)->first()[0];
    expect($event->payload())->toMatchArray([
        'item_name_en' => 'Flour', 'item_name_am' => 'ዱቄት', 'item_kind' => 'ingredient', 'fulfilment' => 'bought_in',
        'location_name_en' => $this->store->name_en, 'location_name_am' => $this->store->name_am,
        'available' => '0.0000', 'on_hand' => '0.0000', 'reserved' => '0.0000',
    ]);
})->group('module:inventory', 'feature');

it('keeps one episode per place: the same item running out in two places is two episodes', function () {
    Event::fake([StockOut::class, StockRecovered::class]);
    $kitchen = stockLocation($this->branch, LocationType::Kitchen);
    stockIn($this->flour, $this->store, '10');
    stockIn($this->flour, $kitchen, '10');

    stockIn($this->flour, $this->store, '-10', MovementReason::Waste);
    Event::assertDispatchedTimes(StockOut::class, 1);
    stockIn($this->flour, $kitchen, '-10', MovementReason::Waste);

    Event::assertDispatchedTimes(StockOut::class, 2);
    expect(DB::table('stock_alert_states')->where('item_id', $this->flour->id)->where('is_active', 1)->count())->toBe(2);
})->group('module:inventory', 'feature');

it('does nothing for a balance nothing ever moved, and nothing for a job whose branch does not own the item', function () {
    Event::fake([StockOut::class, StockRecovered::class]);

    ($this->run)(($this->job)($this->cake, $this->display));
    ($this->run)(new EvaluateBalance(Branch::factory()->create()->id, $this->cake->id, $this->display->id));
    stockIn($this->cake, $this->display, '1');
    ($this->run)(new EvaluateBalance(Branch::factory()->create()->id, $this->cake->id, $this->display->id));

    expect(DB::table('stock_alert_states')->count())->toBe(0);
    Event::assertNotDispatched(StockOut::class);
})->group('module:inventory', 'feature');

it('runs as the person who caused it and leaves the process as it found it', function () {
    stockIn($this->cake, $this->display, '1');
    withActor($this->admin->id, $this->waiter->id, $this->tillStation->id);
    Queue::fake();
    ($this->reserve)($this->cake, '1', $this->display);
    $job = Queue::pushed(EvaluateBalance::class)->last();
    // a different person's request is in flight when the job runs (sync queue inside a request, or a long-lived worker)
    $bystander = makeUser($this->branch, 'cashier');
    withActor($bystander->id, null, null);
    $other = Branch::factory()->create();
    actInBranch($other);
    $seen = null;
    Event::listen(StockOut::class, function (StockOut $event) use (&$seen): void {
        $seen = [app(ActorContext::class)->toArray(), app(CurrentBranch::class)->idOrNull(), $event->causedByUserId];
    });

    ($this->run)($job);

    expect($seen)->toBe([
        ['user_id' => $this->admin->id, 'acting_user_id' => $this->waiter->id, 'shift_instance_id' => null, 'station_id' => $this->tillStation->id, 'device_id' => null],
        $this->branch->id,
        $this->waiter->id,
    ])
        ->and(app(ActorContext::class)->userId())->toBe($bystander->id)
        ->and(app(ActorContext::class)->actingUserId())->toBeNull()
        ->and(app(CurrentBranch::class)->idOrNull())->toBe($other->id);
})->group('module:inventory', 'feature');
