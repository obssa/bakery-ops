<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Listeners\CreateAutoRequest;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Support\AutoRequestUuid;
use App\Shared\Actor\ActorContext;
use App\Shared\Events\ProductionRequested;
use App\Shared\Events\StockOut;
use App\Shared\Models\Branch;
use App\Shared\Tenancy\CurrentBranch;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NotificationEvents;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * CreateAutoRequest (step 2.7b; phase-2 plan sections 5 and 6 item 7): when a product that is kept in stock and made from a
 * recipe runs out where it is sold, one production request is made for the batch size at the station that makes it. Nothing
 * is made for an ingredient, a bought-in or made-to-order product, a product without a recipe or without a producing station,
 * or for a place the product is not sold from. One request per run-out: the unique open stock-out key makes a retried or
 * racing job find the first request and stop; a new run-out after the first request is closed makes a new one. It runs as the
 * person whose movement caused the stock-out, and the Pest queue is synchronous, so these cases drive the real chain
 * (a movement, EvaluateBalance, StockOut, this listener) and the listener on its own.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    $this->runOut = function ($item, $at, string $qty = '3'): void {
        stockIn($item, $at, $qty);
        stockIn($item, $at, '-'.$qty, MovementReason::Waste);
    };
    $this->requests = fn () => ProductionRequest::query()->orderBy('id')->get();
    $this->stockOut = fn (int $alert = 77, array $over = []): StockOut => NotificationEvents::stockOut($this->branch->id, $this->f->cake->id, $this->f->display->id, $alert, $over + [
        'kind' => 'product', 'fulfilment' => 'to_stock', 'causedBy' => $this->f->cashier->id, 'productionStationId' => $this->f->cakeRoomStation->id,
    ]);
});

it('makes one request for the batch size at the producing station when a product runs out where it is sold', function () {
    Event::fake([ProductionRequested::class]);
    withActor($this->f->cashier->id);

    ($this->runOut)($this->f->cake, $this->f->display);

    $request = ($this->requests)()->sole();
    $alert = DB::table('stock_alert_states')->where('item_id', $this->f->cake->id)->where('type', 'out')->sole();
    expect($request->reason)->toBe(RequestReason::StockOut)
        ->and($request->status)->toBe(RequestStatus::Requested)
        ->and($request->product_item_id)->toBe($this->f->cake->id)
        ->and((string) $request->requested_qty)->toBe('12.0000')
        ->and($request->station_id)->toBe($this->f->cakeRoomStation->id)
        ->and($request->open_stock_out_key)->toBe(1)
        ->and($request->source_alert_id)->toBe($alert->id)
        ->and($request->client_uuid)->toBe(AutoRequestUuid::forAlert($this->branch->id, $alert->id))
        ->and($request->requested_by_user_id)->toBe($this->f->cashier->id, 'the person whose movement ran the product out')
        ->and($request->created_by_user_id)->toBe($this->f->cashier->id)
        ->and($request->due_at)->toBeNull()
        ->and($request->assigned_to_user_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'production_request.auto_create')->where('subject_id', $request->id)->where('actor_id', $this->f->cashier->id)->count())->toBe(1);

    Event::assertDispatchedTimes(ProductionRequested::class, 1);
    $event = Event::dispatched(ProductionRequested::class)->first()[0];
    expect($event->payload())->toMatchArray(['request_id' => $request->id, 'reason' => 'stock_out', 'qty' => '12.0000', 'station_id' => $this->f->cakeRoomStation->id, 'requested_by_user_id' => $this->f->cashier->id]);
})->group('gate:p2');

it('uses the stored batch size of a product that has one', function () {
    stockIn($this->f->bread, $this->f->display, '2');
    stockIn($this->f->bread, $this->f->display, '-2', MovementReason::Waste);

    $request = ($this->requests)()->sole();
    expect((string) $request->requested_qty)->toBe('40.0000')->and($request->station_id)->toBe($this->f->bakeryStation->id);
});

it('makes nothing for an ingredient, a bought-in product, a made-to-order product, or a product without a recipe or a station', function () {
    Event::fake([ProductionRequested::class]);
    ($this->runOut)($this->f->flour, $this->f->cakeRoom, '5000');
    ($this->runOut)($this->f->soda, $this->f->display);
    ($this->runOut)($this->f->plainCake, $this->f->display);   // to stock, no recipe
    ($this->runOut)($this->f->orphanCake, $this->f->display);  // a recipe, but nobody makes it
    expect(DB::table('stock_alert_states')->where('type', 'out')->where('is_active', 1)->count())->toBe(4, 'all four ran out');

    $listener = app(CreateAutoRequest::class);
    $listener->handle(($this->stockOut)(78, ['fulfilment' => 'to_order']));
    $listener->handle(($this->stockOut)(79, ['kind' => 'ingredient', 'fulfilment' => 'bought_in']));
    $listener->handle(($this->stockOut)(80, ['fulfilment' => 'bought_in']));

    expect(($this->requests)())->toHaveCount(0);
    Event::assertNotDispatched(ProductionRequested::class);
})->group('gate:p2');

it('makes nothing for a run-out in a place the product is not sold from', function () {
    ($this->runOut)($this->f->cake, $this->f->cakeRoom); // the cake room is where it is made, not where it is sold
    expect(($this->requests)())->toHaveCount(0);

    ($this->runOut)($this->f->cake, $this->f->display);
    expect(($this->requests)())->toHaveCount(1);
});

it('ends quietly for a stock-out nobody is known to have caused', function () {
    Event::fake([ProductionRequested::class]);

    app(CreateAutoRequest::class)->handle(($this->stockOut)(81, ['causedBy' => null]));

    expect(($this->requests)())->toHaveCount(0);
    Event::assertNotDispatched(ProductionRequested::class);
});

it('is idempotent: the same stock-out delivered twice makes one request and announces it once', function () {
    Event::fake([ProductionRequested::class]);
    $event = ($this->stockOut)(82);

    app(CreateAutoRequest::class)->handle($event);
    app(CreateAutoRequest::class)->handle($event);

    expect(($this->requests)())->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'production_request.auto_create')->count())->toBe(1);
    Event::assertDispatchedTimes(ProductionRequested::class, 1);
})->group('gate:p2');

it('finds the open request of an earlier run-out instead of making a second one, and makes a new one once that is closed', function () {
    Event::fake([ProductionRequested::class]);
    withActor($this->f->cashier->id);

    ($this->runOut)($this->f->cake, $this->f->display);           // run-out 1: request A
    stockIn($this->f->cake, $this->f->display, '5');               // recovered
    stockIn($this->f->cake, $this->f->display, '-5', MovementReason::Waste); // run-out 2 while A is still open
    expect(($this->requests)())->toHaveCount(1, 'one open stock-out request per product');

    $first = ($this->requests)()->sole();
    $first->forceFill(['status' => RequestStatus::Cancelled, 'cancelled_at' => now(), 'open_stock_out_key' => null, 'version' => 2])->save();
    stockIn($this->f->cake, $this->f->display, '5');
    stockIn($this->f->cake, $this->f->display, '-5', MovementReason::Waste); // run-out 3 after A is closed

    $all = ($this->requests)();
    expect($all)->toHaveCount(2)
        ->and($all[1]->status)->toBe(RequestStatus::Requested)
        ->and($all[1]->open_stock_out_key)->toBe(1)
        ->and($all[1]->source_alert_id)->not->toBe($first->source_alert_id)
        ->and($all[1]->client_uuid)->not->toBe($first->client_uuid);
    Event::assertDispatchedTimes(ProductionRequested::class, 2);
});

it('restores the branch and the person it found, and works in the branch of the event', function () {
    $other = Branch::factory()->create();
    $before = [app(CurrentBranch::class)->id(), app(ActorContext::class)->userId()];

    app(CreateAutoRequest::class)->handle(($this->stockOut)(83));

    expect([app(CurrentBranch::class)->id(), app(ActorContext::class)->userId()])->toBe($before)
        ->and(ProductionRequest::query()->withoutGlobalScopes()->where('branch_id', $this->branch->id)->count())->toBe(1)
        ->and(ProductionRequest::query()->withoutGlobalScopes()->where('branch_id', $other->id)->count())->toBe(0);

    // an event of a branch that does not exist has nobody to ask
    app(CreateAutoRequest::class)->handle(NotificationEvents::stockOut(999999, $this->f->cake->id, $this->f->display->id, 84, ['kind' => 'product', 'fulfilment' => 'to_stock', 'causedBy' => $this->f->cashier->id]));
    expect(ProductionRequest::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('is queued after commit on the default queue, and registered for StockOut', function () {
    Queue::fake();

    event(($this->stockOut)(85));

    $pushed = Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === CreateAutoRequest::class);
    expect($pushed)->toHaveCount(1)
        ->and($pushed->first()->queue)->toBeNull('the default queue: the cron worker drains only that one')
        ->and(is_subclass_of(CreateAutoRequest::class, ShouldQueue::class))->toBeTrue()
        ->and(is_subclass_of(CreateAutoRequest::class, ShouldHandleEventsAfterCommit::class))->toBeTrue()
        ->and(ProductionRequest::query()->count())->toBe(0, 'nothing is written in the request that dispatched the event');
});
