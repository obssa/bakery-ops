<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Notifications\Listeners\NotifyProductionAssigned;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Events\ProductionAssigned;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * POST /production/requests/{request}/assign (step 2.7b; phase-2 plan section 4): a planner (production.plan) names the
 * baker who makes a request. The baker must be an active person of this branch who holds production.fulfil at the
 * request's station. Only a request nobody has taken yet can be assigned (to someone else again as often as needed); the
 * status does not change. Assigning the person already named is a repeat: 200 and nothing written. Emits ProductionAssigned.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    loginAs($this->f->manager);
    $this->assign = fn ($request, ?int $userId, ?array $body = null) => $this->withHeaders(idempotencyKey())
        ->postJson("/api/v1/production/requests/{$request->id}/assign", $body ?? ['user_id' => $userId]);
});

it('names the baker, keeps the request requested, audits it and announces it to the baker', function () {
    Event::fake([ProductionAssigned::class]);
    $request = $this->f->request();

    $response = ($this->assign)($request, $this->f->baker->id)->assertOk();

    $response->assertJsonPath('id', $request->id)
        ->assertJsonPath('status', 'requested')
        ->assertJsonPath('assigned_to', ['id' => $this->f->baker->id, 'name' => $this->f->baker->name])
        ->assertJsonPath('version', 2);
    $row = $request->fresh();
    expect($row->assigned_to_user_id)->toBe($this->f->baker->id)
        ->and($row->status)->toBe(RequestStatus::Requested)
        ->and($row->accepted_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'production_request.assign')->where('subject_id', $request->id)->where('actor_id', $this->f->manager->id)->count())->toBe(1);

    Event::assertDispatchedTimes(ProductionAssigned::class, 1);
    $event = Event::dispatched(ProductionAssigned::class)->first()[0];
    expect($event->branchId)->toBe($this->branch->id)
        ->and($event->payload())->toMatchArray([
            'request_id' => $request->id,
            'product_item_id' => $this->f->cake->id,
            'product_name_en' => 'Black forest cake',
            'product_name_am' => 'ብላክ ፎረስት ኬክ',
            'qty' => '12.0000',
            'unit' => 'pcs',
            'station_id' => $this->f->cakeRoomStation->id,
            'station_name_en' => $this->f->cakeRoomStation->name_en,
            'station_name_am' => $this->f->cakeRoomStation->name_am,
            'assigned_to_user_id' => $this->f->baker->id,
            'assigned_by_user_id' => $this->f->manager->id,
            'version' => 2,
        ]);
});

it('answers a repeat with the same person 200, writing and announcing nothing; another person replaces the first and is told', function () {
    Event::fake([ProductionAssigned::class]);
    $request = $this->f->request();
    ($this->assign)($request, $this->f->baker->id)->assertOk();
    $audits = AuditLog::query()->count();

    ($this->assign)($request, $this->f->baker->id)->assertOk()->assertJsonPath('version', 2);
    expect(AuditLog::query()->count())->toBe($audits)->and($request->fresh()->version)->toBe(2);
    Event::assertDispatchedTimes(ProductionAssigned::class, 1);

    ($this->assign)($request, $this->f->baker2->id)->assertOk()->assertJsonPath('assigned_to.id', $this->f->baker2->id)->assertJsonPath('version', 3);
    Event::assertDispatchedTimes(ProductionAssigned::class, 2);
    expect(Event::dispatched(ProductionAssigned::class)->last()[0]->payload())->toMatchArray(['assigned_to_user_id' => $this->f->baker2->id, 'version' => 3]);
});

it('takes the admin, who works at every station and holds production.fulfil', function () {
    ($this->assign)($this->f->request(), $this->f->admin->id)->assertOk()->assertJsonPath('assigned_to.id', $this->f->admin->id);
});

it('refuses someone who cannot make it: wrong role, wrong station, inactive, another branch, unknown', function () {
    $request = $this->f->request();
    $gone = makeUser($this->branch, 'cake_baker');
    UserStationAssignment::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $gone->id, 'station_id' => $this->f->cakeRoomStation->id]);
    $gone->forceFill(['is_active' => false])->save();
    $stranger = makeUser(Branch::factory()->create(), 'cake_baker');
    $unassigned = makeUser($this->branch, 'cake_baker');

    foreach ([$this->f->manager->id, $this->f->cashier->id, $this->f->waiter->id, $this->f->breadBaker->id, $unassigned->id, $gone->id, $stranger->id, 999999] as $id) {
        ($this->assign)($request, $id)->assertStatus(422)->assertJsonPath('message_key', 'production.assignee.invalid');
    }
    expect($request->fresh()->assigned_to_user_id)->toBeNull()->and($request->fresh()->version)->toBe(1);
});

it('validates the body', function () {
    $request = $this->f->request();
    foreach ([[], ['user_id' => null], ['user_id' => 'abc'], ['user_id' => 0]] as $body) {
        ($this->assign)($request, null, $body)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    }
});

it('refuses a request somebody has already taken or that is past that, naming the status', function () {
    foreach ([RequestStatus::Accepted, RequestStatus::InProgress, RequestStatus::PendingConfirmation, RequestStatus::Done, RequestStatus::Cancelled] as $status) {
        $request = $this->f->request([], $status);

        ($this->assign)($request, $this->f->baker2->id)->assertStatus(409)
            ->assertJsonPath('message_key', 'state_machine.illegal_transition')
            ->assertJsonPath('from', $status->value);
        expect($request->fresh()->assigned_to_user_id)->toBe($request->assigned_to_user_id);
    }
});

it('is the planners to assign: a cashier, a chef and the bakers are refused at the route', function () {
    $request = $this->f->request();
    foreach ([$this->f->cashier, $this->f->chef, $this->f->baker, $this->f->waiter] as $person) {
        loginAs($person);
        ($this->assign)($request, $this->f->baker->id)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    loginAs($this->f->admin);
    ($this->assign)($request, $this->f->baker->id)->assertOk();
});

it('is 404 for another branch request and queues only the assignment listener', function () {
    $theirs = Branch::factory()->create();
    $foreign = ProductionRequest::factory()->create(['branch_id' => $theirs->id]);
    ($this->assign)($foreign, $this->f->baker->id)->assertStatus(404);

    Queue::fake();
    ($this->assign)($this->f->request(), $this->f->baker->id)->assertOk();
    assertNothingQueuedButStockEvaluation([NotifyProductionAssigned::class]);
});
