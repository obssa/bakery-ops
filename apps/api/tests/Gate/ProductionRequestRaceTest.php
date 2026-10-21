<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\Fulfilment;
use App\Modules\Catalog\Models\Item;
use App\Modules\Inventory\Jobs\EvaluateBalance;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Listeners\CreateAutoRequest;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CommitsForConcurrency;
use Tests\Support\Forks;
use Tests\Support\NotificationEvents;
use Tests\Support\ProductionFixture;

/*
 * Step 2.7b, group concurrency (phase-2 plan sections 5, 6 item 7 and 7), through the whole stack in two processes on two
 * connections. The automatic request is claimed by an INSERT that the unique open stock-out key arbitrates, never by locking a
 * row that may not exist yet (two processes would gap-lock it and deadlock, traps.md "First-row race"):
 *   - two evaluations of one balance at the same moment announce one StockOut, which makes one request;
 *   - the same StockOut delivered to two workers at the same moment makes one request, one audit row, one announcement;
 *   - two different episodes of one product handled at the same moment still make one open request;
 *   - two bakers accepting one request: one wins, the other finds it taken; one baker tapping twice writes once.
 * Every round uses a new product, so the first request of a product is what the children race for. The engine's deadlock
 * counter must not move. CommitsForConcurrency (DatabaseTruncation): the children must see committed rows.
 */

uses(CommitsForConcurrency::class);

const AUTO_RACE_ROUNDS = 30;

function autoRaceDeadlocks(): int
{
    if (DB::getDriverName() === 'pgsql') {
        return (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
    }
    $row = DB::selectOne("show global status like 'Innodb_deadlocks'");

    return $row === null ? 0 : (int) $row->Value;
}

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    withActor($this->f->cashier->id);
    config(['cache.default' => 'database']); // the unique-job and idempotency locks must be shared between the processes, as on the live host

    // a product the cake room makes, new for every round
    $this->product = function (): Item {
        $item = Item::factory()->product(Fulfilment::ToStock, 'pcs')->create([
            'branch_id' => $this->branch->id, 'created_by_user_id' => $this->f->admin->id, 'production_station_id' => $this->f->cakeRoomStation->id,
        ]);
        $this->f->recipe($item, '12', [[$this->f->flour, '1']]);

        return $item;
    };
    // the product is sold out at the display case, in the parent, without evaluating the balance (the jobs are swallowed)
    $this->soldOut = function (Item $item): void {
        $real = app('queue');
        Queue::fake();
        stockIn($item, $this->f->display, '3');
        stockIn($item, $this->f->display, '-3', MovementReason::Waste);
        Queue::swap($real);
    };
    $this->settings = fn () => DB::statement(DB::getDriverName() === 'pgsql' ? "set lock_timeout = '10s'" : 'set session innodb_lock_wait_timeout = 10');
    $this->requestsOf = fn (Item $item) => ProductionRequest::query()->where('product_item_id', $item->id)->get();
    $this->allOk = fn (array $results): bool => array_reduce($results, fn (bool $ok, array $r): bool => $ok && ($r['ok'] ?? false), true);
});

it('makes one request when two evaluations of the same sold-out balance run at the same moment (30 rounds)', function () {
    $deadlocksBefore = autoRaceDeadlocks();
    $failures = [];
    foreach (range(1, AUTO_RACE_ROUNDS) as $round) {
        $item = ($this->product)();
        ($this->soldOut)($item);
        $evaluate = fn (): Closure => function () use ($item): bool {
            ($this->settings)();
            loginAs($this->f->cashier);
            withActor($this->f->cashier->id);
            app()->call([new EvaluateBalance($this->branch->id, $item->id, $this->f->display->id), 'handle']);

            return true;
        };

        $results = Forks::together([$evaluate(), $evaluate()]);

        $requests = ($this->requestsOf)($item);
        $episodes = DB::table('stock_alert_states')->where('item_id', $item->id)->where('type', 'out')->count();
        $audits = DB::table('audit_log')->where('action', 'production_request.auto_create')->whereIn('subject_id', $requests->pluck('id')->all())->count();
        if (! ($this->allOk)($results) || $requests->count() !== 1 || $episodes !== 1 || $audits !== 1 || $requests->first()->open_stock_out_key !== 1) {
            $failures[] = "round {$round}: ".json_encode($results).", {$requests->count()} requests, {$episodes} episodes, {$audits} audits";
        }
    }

    expect($failures)->toBe([])->and(autoRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:production', 'feature');

it('makes one request, one audit row and one announcement when the same stock-out reaches two workers at the same moment (30 rounds)', function () {
    $deadlocksBefore = autoRaceDeadlocks();
    $failures = [];
    foreach (range(1, AUTO_RACE_ROUNDS) as $round) {
        $item = ($this->product)();
        $event = NotificationEvents::stockOut($this->branch->id, $item->id, $this->f->display->id, 100 + $round, [
            'kind' => 'product', 'fulfilment' => 'to_stock', 'causedBy' => $this->f->cashier->id, 'productionStationId' => $this->f->cakeRoomStation->id,
        ]);
        $deliver = fn (): Closure => function () use ($event): bool {
            ($this->settings)();
            withActor($this->f->admin->id);
            app(CreateAutoRequest::class)->handle($event);

            return true;
        };

        $results = Forks::together([$deliver(), $deliver()]);

        $requests = ($this->requestsOf)($item);
        $audits = DB::table('audit_log')->where('action', 'production_request.auto_create')->whereIn('subject_id', $requests->pluck('id')->all())->count();
        if (! ($this->allOk)($results) || $requests->count() !== 1 || $audits !== 1 || $requests->first()->requested_by_user_id !== $this->f->cashier->id) {
            $failures[] = "round {$round}: ".json_encode($results).", {$requests->count()} requests, {$audits} audits";
        }
    }

    expect($failures)->toBe([])->and(autoRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:production', 'feature');

it('keeps one open request when two different episodes of a product are handled at the same moment (30 rounds)', function () {
    $deadlocksBefore = autoRaceDeadlocks();
    $failures = [];
    foreach (range(1, AUTO_RACE_ROUNDS) as $round) {
        $item = ($this->product)();
        $deliver = fn (int $alert): Closure => function () use ($item, $alert): bool {
            ($this->settings)();
            withActor($this->f->admin->id);
            app(CreateAutoRequest::class)->handle(NotificationEvents::stockOut($this->branch->id, $item->id, $this->f->display->id, $alert, [
                'kind' => 'product', 'fulfilment' => 'to_stock', 'causedBy' => $this->f->cashier->id, 'productionStationId' => $this->f->cakeRoomStation->id,
            ]));

            return true;
        };

        $results = Forks::together([$deliver(500 + $round), $deliver(900 + $round)]);

        $requests = ($this->requestsOf)($item);
        if (! ($this->allOk)($results) || $requests->count() !== 1 || $requests->first()->open_stock_out_key !== 1) {
            $failures[] = "round {$round}: ".json_encode($results).", {$requests->count()} requests";
        }
    }

    expect($failures)->toBe([])->and(autoRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:production', 'feature');

it('lets one of two bakers win a request and tells the other it is taken, and writes once when one baker taps twice (30 rounds)', function () {
    $deadlocksBefore = autoRaceDeadlocks();
    $failures = [];
    $accept = fn (int $requestId, $baker): Closure => function () use ($requestId, $baker): array {
        ($this->settings)();
        loginAs($baker);
        $response = $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/requests/{$requestId}/accept", []);

        return ['status' => $response->getStatusCode(), 'message_key' => $response->json('message_key'), 'assigned_to' => $response->json('assigned_to.id')];
    };
    foreach (range(1, AUTO_RACE_ROUNDS) as $round) {
        $two = $this->f->request();
        $results = Forks::together([$accept($two->id, $this->f->baker), $accept($two->id, $this->f->baker2)]);
        $statuses = collect($results)->map(fn ($r) => ($r['ok'] ?? false) ? $r['value']['status'] : -1)->sort()->values()->all();
        $row = $two->fresh();
        if ($statuses !== [200, 409] || $row->status !== RequestStatus::Accepted || $row->version !== 2 || DB::table('audit_log')->where('action', 'production_request.accept')->where('subject_id', $two->id)->count() !== 1) {
            $failures[] = "round {$round} (two bakers): ".json_encode($results).", version {$row->version}";
        }

        $one = $this->f->request();
        $results = Forks::together([$accept($one->id, $this->f->baker), $accept($one->id, $this->f->baker)]);
        $row = $one->fresh();
        foreach ($results as $i => $r) {
            if (($r['ok'] ?? false) !== true || ($r['value']['status'] ?? null) !== 200 || ($r['value']['assigned_to'] ?? null) !== $this->f->baker->id) {
                $failures[] = "round {$round} (one baker twice), process {$i}: ".json_encode($r);
            }
        }
        if ($row->version !== 2 || DB::table('audit_log')->where('action', 'production_request.accept')->where('subject_id', $one->id)->count() !== 1) {
            $failures[] = "round {$round} (one baker twice): version {$row->version}";
        }
    }

    expect($failures)->toBe([])->and(autoRaceDeadlocks())->toBe($deadlocksBefore, 'the engine detected no deadlock at all');
})->group('concurrency', 'module:production', 'feature');
