<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\Stations;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\LocationType;
use App\Modules\Inventory\Jobs\EvaluateBalance;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Notifications\Listeners\ClearStockDedupe;
use App\Modules\Notifications\Listeners\NotifyLineReady;
use App\Modules\Notifications\Listeners\NotifyLineRejected;
use App\Modules\Notifications\Listeners\NotifyStockOut;
use App\Modules\Notifications\Listeners\NotifyTransferDecided;
use App\Modules\Notifications\Listeners\NotifyTransferRequested;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\Dedupe;
use App\Shared\Actor\ActorContext;
use App\Shared\Enums\StationCode;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationEvents;

/*
 * Step 2.5a (phase-2 plan sections 5 and 7): the Notifications listeners for the events that exist (LineReady, LineRejected,
 * StockOut, StockRecovered and the two transfer events). Each is queued after commit on the default queue (the live host
 * drains the database queue once a minute; none of this may slow the request that caused it), resolves who hears of it
 * through Identity's Recipients only, and runs as the person who caused the event with the event's branch resolved, putting
 * back whatever the worker had. Every case here has a Queue::fake twin (the dispatch) and a direct run (the effect).
 */

beforeEach(function () {
    seedRoles();
    $this->branch = actInBranch(Branch::factory()->create());
    $b = $this->branch->id;
    $this->admin = makeUser($this->branch, 'admin');
    $this->manager = makeUser($this->branch, 'manager');
    $this->cashier = makeUser($this->branch, 'cashier');
    $this->waiter = makeUser($this->branch, 'waiter');
    $this->chef = makeUser($this->branch, 'chef');
    $this->baker = makeUser($this->branch, 'cake_baker');
    $this->breadBaker = makeUser($this->branch, 'bread_baker');
    withActor($this->admin->id);
    $this->cakeRoom = Station::factory()->forCode(StationCode::CakeRoom)->create(['branch_id' => $b]);
    $this->bakery = Station::factory()->forCode(StationCode::Bakery)->create(['branch_id' => $b]);
    $this->kitchen = Station::factory()->forCode(StationCode::Kitchen)->create(['branch_id' => $b]);
    $this->store = Station::factory()->forCode(StationCode::Store)->create(['branch_id' => $b]);
    $assign = fn (User $user, Station $station) => app(Stations::class)->replace($user, [['station_id' => $station->id, 'can_count' => true, 'is_overseer' => false]]);
    $assign($this->baker, $this->cakeRoom);
    $assign($this->breadBaker, $this->bakery);
    $assign($this->chef, $this->kitchen);
    $this->keeper = makeUser($this->branch, 'cashier');
    $assign($this->keeper, $this->store);
    $this->heard = fn (string $type) => Notification::query()->where('type', $type)->orderBy('user_id')->pluck('user_id')->all();
    $this->ids = fn (User ...$users) => collect($users)->map(fn (User $u): int => $u->id)->sort()->values()->all();
    /** run every queued listener the fake caught, as a worker would */
    $this->drain = function (): void {
        foreach (Queue::pushed(CallQueuedListener::class) as $job) {
            app($job->class)->{$job->method}(...$job->data);
        }
    };
});

it('queues every listener after commit on the default queue, and each is registered for its event', function () {
    Queue::fake();
    $events = [
        NotifyLineReady::class => NotificationEvents::lineReady($this->branch->id, $this->waiter->id),
        NotifyLineRejected::class => NotificationEvents::lineRejected($this->branch->id, $this->waiter->id),
        NotifyStockOut::class => NotificationEvents::stockOut($this->branch->id),
        ClearStockDedupe::class => NotificationEvents::stockRecovered($this->branch->id),
        NotifyTransferRequested::class => NotificationEvents::transferRequested($this->branch->id, $this->chef->id),
        NotifyTransferDecided::class => NotificationEvents::transferDecided($this->branch->id, $this->chef->id, $this->admin->id),
    ];

    foreach ($events as $listener => $event) {
        event($event);
        $pushed = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === $listener);
        expect($pushed)->toHaveCount(1, "{$listener} is queued once for its event");
        $job = $pushed->first();
        expect($job->queue)->toBeNull('the default queue: the cron worker drains only that one')
            ->and(is_subclass_of($listener, ShouldQueue::class))->toBeTrue()
            ->and(is_subclass_of($listener, ShouldHandleEventsAfterCommit::class))->toBeTrue();
    }
    expect(Notification::query()->count())->toBe(0, 'nothing is written in the request that dispatched the event');
})->group('module:notifications', 'feature');

it('puts a ready line in the inbox of the order owner and nobody else, once however often it is delivered', function () {
    $event = NotificationEvents::lineReady($this->branch->id, $this->waiter->id, 31);

    app(NotifyLineReady::class)->handle($event);
    app(NotifyLineReady::class)->handle($event);

    $lines = Notification::query()->get();
    expect($lines)->toHaveCount(1)
        ->and($lines[0]->user_id)->toBe($this->waiter->id)
        ->and($lines[0]->type)->toBe('line_ready')
        ->and($lines[0]->severity)->toBe('info')
        ->and($lines[0]->title_en)->toBe('Ready: Chicken sandwich')
        ->and($lines[0]->body_am)->toBe('ትዕዛዝ #12፣ ጠረጴዛ T4፡ የዶሮ ሳንድዊች ለማቅረብ ዝግጁ ነው።')
        ->and($lines[0]->deep_link)->toBe('/orders/21')
        ->and($lines[0]->payload['line_id'])->toBe(31)
        ->and($lines[0]->payload['caused_by_user_id'])->toBe($this->waiter->id);
})->group('module:notifications', 'feature');

it('tells nobody when the order owner is no longer active, and nobody of another branch', function () {
    $this->waiter->forceFill(['is_active' => false])->save();
    app(NotifyLineReady::class)->handle(NotificationEvents::lineReady($this->branch->id, $this->waiter->id));

    $other = Branch::factory()->create();
    $stranger = makeUser($other, 'waiter');
    app(NotifyLineReady::class)->handle(NotificationEvents::lineReady($this->branch->id, $stranger->id, 33));

    expect(Notification::query()->withoutGlobalScopes()->count())->toBe(0);
})->group('module:notifications', 'feature');

it('puts a rejected line in the owner inbox with the reason', function () {
    app(NotifyLineRejected::class)->handle(NotificationEvents::lineRejected($this->branch->id, $this->waiter->id, 32));

    $line = Notification::query()->sole();
    expect($line->user_id)->toBe($this->waiter->id)
        ->and($line->type)->toBe('line_rejected')
        ->and($line->severity)->toBe('warning')
        ->and($line->body_en)->toBe('Order #12, table T4: Chicken sandwich was sent back. Reason: Out of stock.')
        ->and($line->body_am)->toContain('ክምችቱ አልቋል።')
        ->and($line->payload['reason'])->toBe('out_of_stock');
})->group('module:notifications', 'feature');

it('tells a stock-out of a product to the station that makes it, the confirmers and the roles, and not to the others', function () {
    $event = NotificationEvents::stockOut($this->branch->id, 11, 4, 21, ['kind' => 'product', 'stationId' => $this->bakery->id, 'productionStationId' => $this->cakeRoom->id, 'causedBy' => $this->waiter->id]);

    app(NotifyStockOut::class)->handle($event);

    expect(($this->heard)('stock_out'))->toBe(($this->ids)($this->admin, $this->manager, $this->baker), 'admin and manager (roles, production.confirm) and the cake room baker; not the bread baker of the place station, not the chef')
        ->and(Notification::query()->first()->payload['caused_by_user_id'])->toBe($this->waiter->id);
})->group('module:notifications', 'feature');

it('falls back to the station of the place for a product that has no producing station, and to the roles alone when it has neither', function () {
    app(NotifyStockOut::class)->handle(NotificationEvents::stockOut($this->branch->id, 11, 4, 21, ['kind' => 'product', 'stationId' => $this->bakery->id]));
    expect(($this->heard)('stock_out'))->toBe(($this->ids)($this->admin, $this->manager, $this->breadBaker));

    Notification::query()->delete();
    DB::table('notification_dedupe')->delete();
    app(NotifyStockOut::class)->handle(NotificationEvents::stockOut($this->branch->id, 11, 4, 22, ['kind' => 'product']));
    expect(($this->heard)('stock_out'))->toBe(($this->ids)($this->admin, $this->manager));
})->group('module:notifications', 'feature');

it('tells a stock-out of an ingredient to the roles and the people assigned to the station of the place', function () {
    app(NotifyStockOut::class)->handle(NotificationEvents::stockOut($this->branch->id, 12, 2, 31, [
        'kind' => 'ingredient', 'fulfilment' => 'bought_in', 'stationId' => $this->kitchen->id, 'itemEn' => 'Flour', 'itemAm' => 'ዱቄት',
    ]));

    expect(($this->heard)('stock_out'))->toBe(($this->ids)($this->admin, $this->manager, $this->chef), 'the chef who cooks from the kitchen hears that the flour is gone; the cake baker does not');
    expect(Notification::query()->first()->title_en)->toBe('Flour is out of stock');
})->group('module:notifications', 'feature');

it('holds a second stock-out of the same item and place until StockRecovered, then tells again', function () {
    $out = fn (int $alert) => NotificationEvents::stockOut($this->branch->id, 11, 4, $alert, ['productionStationId' => $this->cakeRoom->id]);

    app(NotifyStockOut::class)->handle($out(21));
    app(NotifyStockOut::class)->handle($out(21)); // a redelivery of the same event
    app(NotifyStockOut::class)->handle($out(22)); // another episode before the recovery was heard (Notifications' gate is the second layer)
    expect(Notification::query()->count())->toBe(3);

    app(ClearStockDedupe::class)->handle(NotificationEvents::stockRecovered($this->branch->id, 11, 4, 'out', 21));
    app(NotifyStockOut::class)->handle($out(22));

    expect(Notification::query()->count())->toBe(6)
        ->and(Notification::query()->where('dedupe_key', 'stock_out:11:4:22')->count())->toBe(3);
})->group('module:notifications', 'feature');

it('clears only the gate of the item and place that recovered, and of the kind that recovered', function () {
    $gate = app(Dedupe::class);
    $gate->claim('stock_out:11:4', 'stock_out');
    $gate->claim('stock_out:11:5', 'stock_out');
    $gate->claim('stock_out:12:4', 'stock_out');
    $gate->claim('stock_low:11:4', 'stock_low');

    app(ClearStockDedupe::class)->handle(NotificationEvents::stockRecovered($this->branch->id, 11, 4, 'out'));

    expect($gate->isActive('stock_out:11:4'))->toBeFalse()
        ->and($gate->isActive('stock_out:11:5'))->toBeTrue()
        ->and($gate->isActive('stock_out:12:4'))->toBeTrue()
        ->and($gate->isActive('stock_low:11:4'))->toBeTrue('a recovery from out does not re-arm the low alert');

    app(ClearStockDedupe::class)->handle(NotificationEvents::stockRecovered($this->branch->id, 11, 4, 'low'));
    expect($gate->isActive('stock_low:11:4'))->toBeFalse();
})->group('module:notifications', 'feature');

it('clears the gate of the event branch, whatever branch the worker had resolved', function () {
    app(Dedupe::class)->claim('stock_out:11:4', 'stock_out');
    $mine = $this->branch;
    actInBranch(Branch::factory()->create());

    app(ClearStockDedupe::class)->handle(NotificationEvents::stockRecovered($mine->id, 11, 4, 'out'));

    expect(DB::table('notification_dedupe')->where('branch_id', $mine->id)->where('is_active', 1)->count())->toBe(0);
})->group('module:notifications', 'feature');

it('tells a transfer request to the people at the source station and the confirmers, but not to the person who asked', function () {
    app(NotifyTransferRequested::class)->handle(NotificationEvents::transferRequested($this->branch->id, $this->keeper->id, 41, ['fromStationId' => $this->store->id, 'toStationId' => $this->kitchen->id]));
    expect(($this->heard)('transfer_requested'))->toBe(($this->ids)($this->admin, $this->manager), 'the keeper asked; the chef works at the destination');

    Notification::query()->delete();
    app(NotifyTransferRequested::class)->handle(NotificationEvents::transferRequested($this->branch->id, $this->chef->id, 42, ['fromStationId' => $this->store->id, 'toStationId' => $this->kitchen->id]));
    expect(($this->heard)('transfer_requested'))->toBe(($this->ids)($this->admin, $this->manager, $this->keeper), 'admin, manager and the keeper at the store');
    expect(Notification::query()->first()->deep_link)->toBe('/inventory/transfers?transfer=42');
})->group('module:notifications', 'feature');

it('tells the decision of a transfer to the person who asked', function () {
    app(NotifyTransferDecided::class)->handle(NotificationEvents::transferDecided($this->branch->id, $this->chef->id, $this->manager->id, 'rejected', 43));

    $line = Notification::query()->sole();
    expect($line->user_id)->toBe($this->chef->id)
        ->and($line->type)->toBe('transfer_decided')
        ->and($line->title_en)->toBe('Transfer rejected: Flour')
        ->and($line->payload['caused_by_user_id'])->toBe($this->manager->id, 'the decider is the cause');
})->group('module:notifications', 'feature');

it('runs as the person who caused the event in the event branch, and puts back what the worker had', function () {
    $mine = $this->branch;
    $workerBranch = actInBranch(Branch::factory()->create());
    $workerUser = makeUser($workerBranch, 'admin');
    withActor($workerUser->id);
    $probe = new class
    {
        /** @var list<array<string, mixed>> */
        public array $seen = [];
    };
    DB::listen(function () use ($probe): void {
        $probe->seen[] = ['actor' => app(ActorContext::class)->userId(), 'branch' => app(CurrentBranch::class)->idOrNull()];
    });

    app(NotifyStockOut::class)->handle(NotificationEvents::stockOut($mine->id, 11, 4, 21, ['causedBy' => $this->waiter->id]));

    expect(Notification::query()->withoutGlobalScopes()->where('branch_id', $mine->id)->count())->toBeGreaterThan(0)
        ->and(Notification::query()->withoutGlobalScopes()->first()->payload['caused_by_user_id'])->toBe($this->waiter->id)
        ->and(app(ActorContext::class)->userId())->toBe($workerUser->id, 'the worker previous actor is back')
        ->and(app(CurrentBranch::class)->id())->toBe($workerBranch->id, 'and its branch')
        ->and(collect($probe->seen)->pluck('actor')->unique()->all())->toContain($this->waiter->id);
})->group('module:notifications', 'feature');

it('does nothing for a branch that no longer exists', function () {
    app(NotifyLineReady::class)->handle(NotificationEvents::lineReady(987654, $this->waiter->id));

    expect(Notification::query()->withoutGlobalScopes()->count())->toBe(0);
})->group('module:notifications', 'feature');

it('follows a stock-out from the movement to the inbox as the person who made the movement, whoever the worker is by then', function () {
    $till = Station::factory()->forCode(StationCode::Till)->create(['branch_id' => $this->branch->id]);
    $display = stockLocation($this->branch, LocationType::DisplayCase, $till);
    $cake = App\Modules\Catalog\Models\Item::factory()->product(App\Modules\Catalog\Enums\Fulfilment::ToStock, 'pcs')->create([
        'branch_id' => $this->branch->id, 'name_en' => 'Honey cake', 'name_am' => 'የማር ኬክ', 'production_station_id' => $this->cakeRoom->id,
    ]);
    stockIn($cake, $display, '1');
    Queue::fake();

    // the cashier sells the last one; the evaluation is queued behind the sale
    withActor($this->cashier->id);
    stockIn($cake, $display, '-1', MovementReason::Waste);
    expect(Queue::pushed(EvaluateBalance::class))->toHaveCount(1)->and(Notification::query()->count())->toBe(0);

    // the worker that picks it up is somebody else entirely
    withActor($this->admin->id);
    $job = Queue::pushed(EvaluateBalance::class)->first();
    app()->call([$job, 'handle']);
    ($this->drain)();

    $lines = Notification::query()->where('type', 'stock_out')->orderBy('user_id')->get();
    expect($lines->pluck('user_id')->all())->toBe(($this->ids)($this->admin, $this->manager, $this->baker))
        ->and($lines->pluck('payload.caused_by_user_id')->unique()->all())->toBe([$this->cashier->id])
        ->and($lines[0]->title_en)->toBe('Honey cake is out of stock')
        ->and(app(ActorContext::class)->userId())->toBe($this->admin->id);
})->group('module:notifications', 'feature');
