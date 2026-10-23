<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Contracts\ShiftAttacher;
use App\Modules\Identity\Models\User;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use App\Shared\Models\Station;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * POST /production/requests/{request}/accept (step 2.7b; phase-2 plan section 4): a baker takes a request. They must hold
 * production.fulfil at the request's station (the admin works at every station). A request assigned to someone else is
 * theirs, not yours (409 production.request.taken). Accepting what is already accepted by you is a repeat: 200, nothing
 * written. Accept calls Identity's ShiftAttacher (a no-op until Phase 3) and records what it answered in the audit context.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    loginAs($this->f->baker);
    $this->accept = fn (ProductionRequest $request) => $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/requests/{$request->id}/accept", []);
    $this->audits = fn (ProductionRequest $request) => AuditLog::query()->where('action', 'production_request.accept')->where('subject_id', $request->id);
});

it('moves a requested request to accepted for the baker, audited, with the shift answer in the audit context', function () {
    $request = $this->f->request();
    // the floor opens the cake room's shift when a rule is on (the default since step 3.7e), and the baker joins it; with the rules off there is none
    $running = DB::table('shift_instances')->where('station_id', $request->station_id)->whereNotNull('open_key')->value('id');

    $response = ($this->accept)($request)->assertOk();

    $response->assertJsonPath('status', 'accepted')
        ->assertJsonPath('assigned_to', ['id' => $this->f->baker->id, 'name' => $this->f->baker->name])
        ->assertJsonPath('version', 2);
    $row = $request->fresh();
    expect($row->status)->toBe(RequestStatus::Accepted)
        ->and($row->assigned_to_user_id)->toBe($this->f->baker->id)
        ->and($row->accepted_at)->not->toBeNull();
    $audit = ($this->audits)($request)->sole();
    expect($audit->actor_id)->toBe($this->f->baker->id)
        ->and($audit->context)->toBe(['shift_instance_id' => $running === null ? null : (int) $running])
        ->and($audit->before['status'])->toBe('requested')
        ->and($audit->after['status'])->toBe('accepted');
});

it('calls the shift attacher with the baker and the request station and records its answer', function () {
    $calls = new ArrayObject;
    $this->app->bind(ShiftAttacher::class, fn () => new class($calls) implements ShiftAttacher
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public function attach(User $user, Station $station): ?int
        {
            $this->calls[] = [$user->id, $station->id];

            return 91;
        }
    });
    $request = $this->f->request();

    ($this->accept)($request)->assertOk();

    expect($calls->getArrayCopy())->toBe([[$this->f->baker->id, $this->f->cakeRoomStation->id]])
        ->and(($this->audits)($request)->sole()->context)->toBe(['shift_instance_id' => 91]);
});

it('takes a request that was assigned to the baker, and refuses one assigned to someone else', function () {
    $mine = $this->f->request(['assigned_to_user_id' => $this->f->baker->id]);
    $theirs = $this->f->request(['assigned_to_user_id' => $this->f->baker2->id]);

    ($this->accept)($mine)->assertOk()->assertJsonPath('status', 'accepted');

    ($this->accept)($theirs)->assertStatus(409)->assertJsonPath('message_key', 'production.request.taken');
    expect($theirs->fresh()->status)->toBe(RequestStatus::Requested)
        ->and($theirs->fresh()->assigned_to_user_id)->toBe($this->f->baker2->id)
        ->and(($this->audits)($theirs)->count())->toBe(0);
});

it('answers a repeat accept 200 and writes nothing; another baker finds it taken', function () {
    $request = $this->f->request();
    ($this->accept)($request)->assertOk();
    $audits = AuditLog::query()->count();

    ($this->accept)($request)->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('version', 2);
    expect(AuditLog::query()->count())->toBe($audits)->and($request->fresh()->version)->toBe(2);

    loginAs($this->f->baker2);
    ($this->accept)($request)->assertStatus(409)->assertJsonPath('message_key', 'production.request.taken');
});

it('refuses a request that is past accepting, naming its status', function () {
    foreach ([RequestStatus::InProgress, RequestStatus::PendingConfirmation, RequestStatus::Done, RequestStatus::Cancelled] as $status) {
        $request = $this->f->request(['assigned_to_user_id' => $this->f->baker->id], $status);

        ($this->accept)($request)->assertStatus(409)
            ->assertJsonPath('message_key', 'state_machine.illegal_transition')
            ->assertJsonPath('from', $status->value)
            ->assertJsonPath('to', 'accepted');
        expect($request->fresh()->status)->toBe($status);
    }
});

it('needs production.fulfil at the request station: another station baker is refused, the admin works everywhere', function () {
    $request = $this->f->request();

    loginAs($this->f->breadBaker);
    ($this->accept)($request)->assertStatus(403)->assertJsonPath('message_key', 'production.station.forbidden');
    expect($request->fresh()->status)->toBe(RequestStatus::Requested);

    $unassigned = makeUser($this->branch, 'cake_baker');
    loginAs($unassigned);
    ($this->accept)($request)->assertStatus(403)->assertJsonPath('message_key', 'production.station.forbidden');

    loginAs($this->f->admin);
    ($this->accept)($request)->assertOk()->assertJsonPath('assigned_to.id', $this->f->admin->id);
});

it('is refused at the route to everyone without production.fulfil', function () {
    $request = $this->f->request();
    foreach ([$this->f->manager, $this->f->cashier, $this->f->chef, $this->f->waiter] as $person) {
        loginAs($person);
        ($this->accept)($request)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    }
    expect($request->fresh()->status)->toBe(RequestStatus::Requested);
});

it('is 404 for another branch request, and queues nothing', function () {
    $foreign = ProductionRequest::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    ($this->accept)($foreign)->assertStatus(404);

    Queue::fake();
    ($this->accept)($this->f->request())->assertOk();
    assertNothingQueuedButStockEvaluation();
});
