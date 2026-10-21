<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Models\Location;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * POST /production/requests/{request}/start (step 2.7c; phase-2 plan section 4): the baker who accepted a request presses Start.
 * A batch row is made (one per request) with the recipe version the baker sees, the planned quantity and the two places a
 * batch will use, all fixed now so a later change of configuration does not move an open batch. The request becomes
 * in_progress. Nothing is written to the ledger. The batch has a client UUID, so an offline replay with a lost key reaches the
 * same batch.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    loginAs($this->f->baker);
    $this->start = fn (ProductionRequest $request, ?string $uuid = null) => $this->withHeaders(idempotencyKey())
        ->postJson("/api/v1/production/requests/{$request->id}/start", ['client_uuid' => $uuid ?? (string) Str::uuid()]);
});

it('starts an accepted request: a started batch with the recipe version, the quantity and both places, the request in progress', function () {
    $request = $this->f->acceptedRequest(null, ['requested_qty' => '18']);
    $movements = DB::table('stock_movements')->count();

    $response = ($this->start)($request)->assertOk();

    $response->assertJsonPath('status', 'in_progress')
        ->assertJsonPath('version', 3)
        ->assertJsonPath('batch.status', 'started')
        ->assertJsonPath('batch.recipe_version', 1)
        ->assertJsonPath('batch.planned_qty', '18.0000')
        ->assertJsonPath('batch.produced_qty', null)
        ->assertJsonPath('batch.send_back_count', 0);
    $batch = ProductionBatch::query()->sole();
    expect($batch->request_id)->toBe($request->id)
        ->and($batch->status)->toBe(BatchStatus::Started)
        ->and($batch->station_id)->toBe($this->f->cakeRoomStation->id)
        ->and($batch->recipe_id)->toBe($this->f->cakeRecipe->id)
        ->and($batch->recipe_version)->toBe(1)
        ->and($batch->consume_location_id)->toBe($this->f->cakeRoom->id, 'where the station works from')
        ->and($batch->output_location_id)->toBe($this->f->display->id, 'where the pad reads a cake')
        ->and($batch->started_by_user_id)->toBe($this->f->baker->id)
        ->and($batch->started_at)->not->toBeNull()
        ->and($request->fresh()->status)->toBe(RequestStatus::InProgress)
        ->and(DB::table('stock_movements')->count())->toBe($movements, 'Start moves no stock');
    $audit = AuditLog::query()->where('action', 'production_batch.start')->sole();
    expect($audit->actor_id)->toBe($this->f->baker->id)->and($audit->subject_id)->toBe($batch->id);
});

it('records the version the baker sees: a newer version published later does not change an open batch', function () {
    $request = $this->f->acceptedRequest();
    ($this->start)($request)->assertOk();
    $this->f->cakeRecipe->forceFill(['is_active' => false])->save();
    $this->f->recipe($this->f->cake, '12', [[$this->f->flour, '9']], [], 2);

    expect(ProductionBatch::query()->sole()->recipe_version)->toBe(1);
});

it('answers a repeat of the same client uuid with the same batch and writes nothing', function () {
    $request = $this->f->acceptedRequest();
    $uuid = (string) Str::uuid();
    ($this->start)($request, $uuid)->assertOk();
    $audits = AuditLog::query()->count();

    ($this->start)($request, $uuid)->assertOk()->assertJsonPath('status', 'in_progress')->assertJsonPath('batch.client_uuid', $uuid);
    expect(ProductionBatch::query()->count())->toBe(1)->and(AuditLog::query()->count())->toBe($audits);
});

it('refuses a request that was not accepted, naming what it needs', function () {
    $request = $this->f->request();

    ($this->start)($request)->assertStatus(409)->assertJsonPath('message_key', 'production.request.not_accepted');
    expect(ProductionBatch::query()->count())->toBe(0)->and($request->fresh()->status)->toBe(RequestStatus::Requested);
});

it('refuses a request that is past starting, and a second batch with another client uuid', function () {
    foreach ([RequestStatus::PendingConfirmation, RequestStatus::Done, RequestStatus::Cancelled] as $status) {
        $request = $this->f->request(['assigned_to_user_id' => $this->f->baker->id], $status);
        ($this->start)($request)->assertStatus(409)
            ->assertJsonPath('message_key', 'state_machine.illegal_transition')
            ->assertJsonPath('from', $status->value)
            ->assertJsonPath('to', 'in_progress');
    }

    $started = $this->f->startedBatch();
    ($this->start)($started->request)->assertStatus(409)->assertJsonPath('message_key', 'state_machine.illegal_transition');
    expect(ProductionBatch::query()->count())->toBe(1);
});

it('is the assignee who starts: another baker of the station finds it taken, the admin too', function () {
    $request = $this->f->acceptedRequest($this->f->baker2);

    ($this->start)($request)->assertStatus(409)->assertJsonPath('message_key', 'production.request.taken');
    loginAs($this->f->admin);
    ($this->start)($request)->assertStatus(409)->assertJsonPath('message_key', 'production.request.taken');
    expect(ProductionBatch::query()->count())->toBe(0);
});

it('needs production.fulfil at the request station', function () {
    $request = $this->f->acceptedRequest();

    loginAs($this->f->breadBaker);
    ($this->start)($request)->assertStatus(403)->assertJsonPath('message_key', 'production.station.forbidden');
    foreach ([$this->f->manager, $this->f->cashier, $this->f->chef, $this->f->waiter] as $person) {
        loginAs($person);
        ($this->start)($request)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect(ProductionBatch::query()->count())->toBe(0);
});

it('is 422 production.locations.unresolved when the station or the product has no stock location', function () {
    $request = $this->f->acceptedRequest();
    Location::query()->whereKey($this->f->cakeRoom->id)->delete();

    ($this->start)($request)->assertStatus(422)->assertJsonPath('message_key', 'production.locations.unresolved');
    expect(ProductionBatch::query()->count())->toBe(0)->and($request->fresh()->status)->toBe(RequestStatus::Accepted);

    $this->f->cakeRoom->restore();
    Location::query()->whereKey($this->f->display->id)->delete();
    ($this->start)($request)->assertStatus(422)->assertJsonPath('message_key', 'production.locations.unresolved');
    expect(ProductionBatch::query()->count())->toBe(0);
});

it('validates the batch client uuid', function () {
    $request = $this->f->acceptedRequest();

    $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/requests/{$request->id}/start", [])->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/requests/{$request->id}/start", ['client_uuid' => 'not-a-uuid'])->assertStatus(422);
    expect(ProductionBatch::query()->count())->toBe(0);
});

it('is 404 for another branch request, and queues nothing but the stock evaluation', function () {
    $foreign = ProductionRequest::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    ($this->start)($foreign)->assertStatus(404);

    Queue::fake();
    ($this->start)($this->f->acceptedRequest())->assertOk();
    assertNothingQueuedButStockEvaluation();
});
