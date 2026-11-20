<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use Illuminate\Support\Str;
use Tests\Support\ActionsMatrix;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * The `actions` of a production request (ADR 0032): for every state a request can be in, at a station with and without an overseer,
 * and for every role of the bakery, the list GET /production/requests/{id} gives that person is exactly the set of endpoints that
 * accept that person's move now and change the request. The board's buttons and drops read the list, so a list that offers what the
 * API refuses (or hides what it accepts) fails here, naming the state, the person and the move.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    $this->f->stockCakeRoom();
    $this->f->requireOverseer();
    $this->overseer = makeUser($this->branch, 'cake_baker');
    UserStationAssignment::factory()->overseer()->create(['branch_id' => $this->branch->id, 'user_id' => $this->overseer->id, 'station_id' => $this->f->cakeRoomStation->id]);
});

it('lists exactly the moves the endpoints accept, in every state and for every role', function () {
    $f = $this->f;
    $records = [
        'requested, nobody named' => fn (): ProductionRequest => $f->request(),
        'requested, given to the second baker' => fn (): ProductionRequest => $f->request(['assigned_to_user_id' => $f->baker2->id]),
        'requested by the waiter' => fn (): ProductionRequest => $f->request(['requested_by_user_id' => $f->waiter->id, 'created_by_user_id' => $f->waiter->id]),
        'accepted by the baker' => fn (): ProductionRequest => $f->acceptedRequest(),
        'in the oven' => fn (): ProductionRequest => $f->startedBatch()->request,
        'in the oven, no overseer at the station' => function () use ($f): ProductionRequest {
            $f->requireOverseer(false);

            return $f->startedBatch()->request;
        },
        'waiting for the sign-off' => fn (): ProductionRequest => $f->finishedBatch()->request,
        'done' => fn (): ProductionRequest => $f->confirmedBatch()->request,
        'cancelled' => fn (): ProductionRequest => $f->request(['cancelled_at' => now()], RequestStatus::Cancelled),
    ];
    $people = [
        'admin' => $f->admin, 'manager' => $f->manager, 'cashier' => $f->cashier, 'waiter' => $f->waiter, 'chef' => $f->chef,
        'baker' => $f->baker, 'second baker' => $f->baker2, 'bread baker' => $f->breadBaker, 'overseer' => $this->overseer,
    ];
    $post = fn (string $uri, array $body = []) => $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/{$uri}", $body);
    $batch = fn (ProductionRequest $request): ?ProductionBatch => ProductionBatch::query()->where('request_id', $request->id)->first();
    $calls = [
        'assign' => fn (ProductionRequest $r) => $post("requests/{$r->id}/assign", ['user_id' => $r->assigned_to_user_id === $f->baker2->id ? $f->baker->id : $f->baker2->id]),
        'accept' => fn (ProductionRequest $r) => $post("requests/{$r->id}/accept"),
        'start' => fn (ProductionRequest $r) => $post("requests/{$r->id}/start", ['client_uuid' => (string) Str::uuid()]),
        'finish' => fn (ProductionRequest $r) => ($b = $batch($r)) ? $post("batches/{$b->id}/done", ['produced_qty' => '12', 'waste_qty' => '0']) : null,
        'confirm' => fn (ProductionRequest $r) => ($b = $batch($r)) ? $post("batches/{$b->id}/confirm") : null,
        'send_back' => fn (ProductionRequest $r) => ($b = $batch($r)) ? $post("batches/{$b->id}/send-back", ['note' => 'Underbaked']) : null,
        'cancel' => fn (ProductionRequest $r) => $post("requests/{$r->id}/cancel", ['reason' => 'Not needed']),
    ];
    $listed = function (ProductionRequest $r, User $who): array {
        $response = $this->getJson("/api/v1/production/requests/{$r->id}");

        return $response->status() === 200 ? $response->json('actions') : [];
    };
    $fingerprint = function (ProductionRequest $r) use ($batch): array {
        $row = ProductionRequest::query()->findOrFail($r->id);
        $b = $batch($r);

        return [$row->status->value, $row->assigned_to_user_id, $row->version, $b?->status->value, $b?->version];
    };

    expect(ActionsMatrix::mismatches($records, $people, $calls, $listed, $fingerprint))->toBe([]);
});

it('gives the board the same list as the card, and names what the baker and the overseer do next', function () {
    $mine = $this->f->acceptedRequest();
    $waiting = $this->f->finishedBatch()->request;

    loginAs($this->f->baker);
    $rows = collect($this->getJson('/api/v1/production/requests')->assertOk()->json('data'))->keyBy('id');
    expect($rows[$mine->id]['actions'])->toBe($this->getJson("/api/v1/production/requests/{$mine->id}")->json('actions'))
        ->and($rows[$mine->id]['actions'])->toContain('start')
        ->and($rows[$waiting->id]['actions'])->toBe([], 'the baker who made a batch never signs it off');

    loginAs($this->overseer);
    expect($this->getJson("/api/v1/production/requests/{$waiting->id}")->json('actions'))->toBe(['confirm', 'send_back']);
});
