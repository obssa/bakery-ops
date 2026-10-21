<?php

declare(strict_types=1);

use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * The whole loop of the plan through HTTP and the (synchronous) Pest queue, step 2.7c: a product runs out at the display case ->
 * StockOut -> an automatic request -> the baker accepts, starts and presses Done -> the batch is confirmed -> the output
 * lands at the display case -> StockChanged -> the evaluation closes the stock-out episode (StockRecovered) -> the request is
 * done and its open key is free -> a later run-out is a new episode and makes a new request. The inbox gets the lines of the
 * chain, each with the person who caused it.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    $this->f->stockCakeRoom('20000', '20000');
    $this->post = fn ($person, string $uri, array $body = []) => tap($this->withHeaders(idempotencyKey()), fn () => loginAs($person))->postJson($uri, $body);
});

it('clears the stock-out when the batch lands, so the next run-out makes a new request', function () {
    withActor($this->f->cashier->id);
    stockIn($this->f->cake, $this->f->display, '1');
    stockIn($this->f->cake, $this->f->display, '-1', MovementReason::Waste);
    $first = ProductionRequest::query()->sole();
    expect($first->open_stock_out_key)->toBe(1)
        ->and(DB::table('stock_alert_states')->where('item_id', $this->f->cake->id)->where('type', 'out')->whereNull('cleared_at')->count())->toBe(1);

    ($this->post)($this->f->baker, "/api/v1/production/requests/{$first->id}/accept")->assertOk();
    $started = ($this->post)($this->f->baker, "/api/v1/production/requests/{$first->id}/start", ['client_uuid' => (string) Str::uuid()])->assertOk();
    $batchId = $started->json('batch.id');
    ($this->post)($this->f->baker, "/api/v1/production/batches/{$batchId}/done", ['produced_qty' => '12', 'waste_qty' => '0'])->assertOk()->assertJsonPath('status', 'confirmed');

    $first = $first->fresh();
    expect($first->status)->toBe(RequestStatus::Done)
        ->and($first->open_stock_out_key)->toBeNull()
        ->and(stockRow($this->f->cake, $this->f->display)->on_hand)->toBe('12.0000')
        ->and(DB::table('stock_alert_states')->where('item_id', $this->f->cake->id)->where('type', 'out')->whereNull('cleared_at')->count())->toBe(0, 'StockRecovered closed the episode');

    withActor($this->f->cashier->id);
    stockIn($this->f->cake, $this->f->display, '-12', MovementReason::Waste);

    $requests = ProductionRequest::query()->orderBy('id')->get();
    expect($requests)->toHaveCount(2)
        ->and($requests[1]->status)->toBe(RequestStatus::Requested)
        ->and($requests[1]->open_stock_out_key)->toBe(1)
        ->and($requests[1]->id)->not->toBe($first->id);
});

it('tells the people of the chain: the baker of the request, the requester that the batch is done', function () {
    withActor($this->f->cashier->id);
    stockIn($this->f->cake, $this->f->display, '1');
    stockIn($this->f->cake, $this->f->display, '-1', MovementReason::Waste);
    $request = ProductionRequest::query()->sole();
    ($this->post)($this->f->baker, "/api/v1/production/requests/{$request->id}/accept")->assertOk();
    $batchId = ($this->post)($this->f->baker, "/api/v1/production/requests/{$request->id}/start", ['client_uuid' => (string) Str::uuid()])->json('batch.id');

    ($this->post)($this->f->baker, "/api/v1/production/batches/{$batchId}/done", ['produced_qty' => '12', 'waste_qty' => '0'])->assertOk();

    $done = Notification::query()->where('type', 'batch_done')->get();
    expect($done->pluck('user_id')->all())->toBe([$this->f->cashier->id], 'the requester; the baker confirmed it themselves')
        ->and($done->first()->payload['caused_by_user_id'])->toBe($this->f->baker->id)
        ->and($done->first()->deep_link)->toBe("/production?request={$request->id}");
});

it('asks the overseer where the station needs one, and tells the requester once the overseer confirms', function () {
    $this->f->requireOverseer();
    withActor($this->f->cashier->id);
    stockIn($this->f->cake, $this->f->display, '1');
    stockIn($this->f->cake, $this->f->display, '-1', MovementReason::Waste);
    $request = ProductionRequest::query()->sole();
    ($this->post)($this->f->baker, "/api/v1/production/requests/{$request->id}/accept")->assertOk();
    $batchId = ($this->post)($this->f->baker, "/api/v1/production/requests/{$request->id}/start", ['client_uuid' => (string) Str::uuid()])->json('batch.id');

    ($this->post)($this->f->baker, "/api/v1/production/batches/{$batchId}/done", ['produced_qty' => '12', 'waste_qty' => '0'])->assertOk()->assertJsonPath('status', 'finished');

    expect(stockRow($this->f->cake, $this->f->display)->on_hand ?? '0.0000')->toBe('0.0000', 'nothing in stock until the overseer signs')
        ->and(Notification::query()->where('type', 'batch_pending_confirmation')->orderBy('user_id')->pluck('user_id')->all())->toBe([$this->f->admin->id, $this->f->manager->id]);

    ($this->post)($this->f->manager, "/api/v1/production/batches/{$batchId}/confirm")->assertOk();

    expect(stockRow($this->f->cake, $this->f->display)->on_hand)->toBe('12.0000')
        ->and(Notification::query()->where('type', 'batch_done')->pluck('user_id')->sort()->values()->all())->toBe(collect([$this->f->cashier->id, $this->f->baker->id])->sort()->values()->all());
});
