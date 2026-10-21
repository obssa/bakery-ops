<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Production\Contracts\Requests;
use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * POST /production/requests/{request}/cancel (step 2.7b; phase-2 plan section 4): a planner (production.plan) cancels any
 * request that has not started; the person who asked cancels their own while it is requested or accepted. A request whose
 * batch is started or finished is finished or sent back, never cancelled (409 production.request.in_progress). Cancelling what
 * is cancelled is a repeat: 200, nothing written. A cancelled stock-out request gives up the open key, so the next run-out of
 * the product asks again.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    loginAs($this->f->manager);
    $this->cancel = fn (ProductionRequest $request, array $body = []) => $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/requests/{$request->id}/cancel", $body);
    $this->audits = fn (ProductionRequest $request) => AuditLog::query()->where('action', 'production_request.cancel')->where('subject_id', $request->id);
});

it('lets a planner cancel a requested or accepted request with a reason, audited', function () {
    $requested = $this->f->request();
    $accepted = $this->f->request(['assigned_to_user_id' => $this->f->baker->id], RequestStatus::Accepted);

    $response = ($this->cancel)($requested, ['reason' => 'No ingredients today'])->assertOk();
    ($this->cancel)($accepted)->assertOk();

    $response->assertJsonPath('status', 'cancelled')->assertJsonPath('cancel_reason', 'No ingredients today')->assertJsonPath('version', 2);
    expect($response->json('cancelled_at'))->not->toBeNull();
    $row = $requested->fresh();
    expect($row->status)->toBe(RequestStatus::Cancelled)
        ->and($row->cancelled_at)->not->toBeNull()
        ->and($row->cancel_reason)->toBe('No ingredients today')
        ->and($accepted->fresh()->status)->toBe(RequestStatus::Cancelled)
        ->and($accepted->fresh()->cancel_reason)->toBeNull()
        ->and(($this->audits)($requested)->where('actor_id', $this->f->manager->id)->count())->toBe(1);
});

it('lets the person who asked cancel their own request while it is requested or accepted, and nobody else', function () {
    $mine = $this->f->request(['requested_by_user_id' => $this->f->chef->id, 'created_by_user_id' => $this->f->chef->id]);
    $mineAccepted = $this->f->request(['requested_by_user_id' => $this->f->chef->id, 'created_by_user_id' => $this->f->chef->id, 'assigned_to_user_id' => $this->f->baker->id], RequestStatus::Accepted);
    $cashiers = $this->f->request();

    loginAs($this->f->chef);
    ($this->cancel)($mine)->assertOk()->assertJsonPath('status', 'cancelled');
    ($this->cancel)($mineAccepted)->assertOk();
    ($this->cancel)($cashiers)->assertStatus(403)->assertJsonPath('message_key', 'production.cancel.forbidden');

    loginAs($this->f->baker);
    ($this->cancel)($cashiers)->assertStatus(403)->assertJsonPath('message_key', 'production.cancel.forbidden');
    expect($cashiers->fresh()->status)->toBe(RequestStatus::Requested);
});

it('refuses a person with no view of production at the route', function () {
    $request = $this->f->request(['requested_by_user_id' => $this->f->waiter->id, 'created_by_user_id' => $this->f->waiter->id]);
    loginAs($this->f->waiter);

    ($this->cancel)($request)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
});

it('answers a repeat 200 and writes nothing, but only to someone allowed to cancel it', function () {
    $request = $this->f->request();
    ($this->cancel)($request, ['reason' => 'first'])->assertOk();
    $audits = AuditLog::query()->count();

    ($this->cancel)($request, ['reason' => 'second'])->assertOk()->assertJsonPath('cancel_reason', 'first')->assertJsonPath('version', 2);
    expect(AuditLog::query()->count())->toBe($audits);

    loginAs($this->f->baker);
    ($this->cancel)($request)->assertStatus(403)->assertJsonPath('message_key', 'production.cancel.forbidden');
});

it('refuses to cancel what has started: in progress or waiting for confirmation, for a planner and the requester alike', function () {
    foreach ([RequestStatus::InProgress, RequestStatus::PendingConfirmation] as $status) {
        $request = $this->f->request(['assigned_to_user_id' => $this->f->baker->id], $status);

        foreach ([$this->f->manager, $this->f->cashier] as $person) {
            loginAs($person);
            ($this->cancel)($request)->assertStatus(409)
                ->assertJsonPath('message_key', 'production.request.in_progress')
                ->assertJsonPath('status', $status->value);
        }
        expect($request->fresh()->status)->toBe($status);
    }
});

it('refuses to cancel a done request, naming its status', function () {
    $request = $this->f->request([], RequestStatus::Done);

    ($this->cancel)($request)->assertStatus(409)
        ->assertJsonPath('message_key', 'state_machine.illegal_transition')
        ->assertJsonPath('from', 'done')
        ->assertJsonPath('to', 'cancelled');
});

it('gives up the open stock-out key, so the next run-out asks again', function () {
    $request = $this->f->request([], null, true);
    expect($request->open_stock_out_key)->toBe(1);

    ($this->cancel)($request)->assertOk();

    expect($request->fresh()->open_stock_out_key)->toBeNull();
    withActor($this->f->cashier->id);
    $again = app(Requests::class)->create($this->f->cake->id, null, null, RequestReason::StockOut);
    expect($again->id)->not->toBe($request->id)->and($again->open_stock_out_key)->toBe(1);
});

it('validates the reason, is 404 for another branch, and queues nothing', function () {
    $request = $this->f->request();
    ($this->cancel)($request, ['reason' => str_repeat('x', 256)])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    ($this->cancel)($request, ['reason' => ['a']])->assertStatus(422);
    expect($request->fresh()->status)->toBe(RequestStatus::Requested);

    $foreign = ProductionRequest::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    ($this->cancel)($foreign)->assertStatus(404);

    Queue::fake();
    ($this->cancel)($request)->assertOk();
    assertNothingQueuedButStockEvaluation();
});
