<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Contracts\ActingAsSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Shared\Events\BatchDone;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * POST /production/batches/{batch}/confirm (step 2.7c; phase-2 plan sections 4 and 10.6): where a station needs an overseer the
 * finished batch waits for one. Whoever holds production.confirm (admin, manager) or is the overseer flagged at the station
 * may sign it off, never the person who pressed Done (409 production.batch.self_confirm; the database CHECK refuses it too),
 * so an admin working alone cannot confirm their own batch (open question 4: the default stands, it is refused). Confirm is
 * the one place the stock is written: N production_consume rows and one production_output row in one Ledger::appendMany. A
 * short ingredient is 409 and the batch stays finished.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    $this->f->stockCakeRoom();
    $this->f->requireOverseer();
    $this->overseer = makeUser($this->branch, 'cake_baker');
    UserStationAssignment::factory()->overseer()->create(['branch_id' => $this->branch->id, 'user_id' => $this->overseer->id, 'station_id' => $this->f->cakeRoomStation->id]);
    loginAs($this->f->manager);
    $this->confirm = fn (ProductionBatch $batch) => $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/batches/{$batch->id}/confirm", []);
    $this->movements = fn () => DB::table('stock_movements')->where('ref_type', 'production_batch')->orderBy('id')->get();
    $this->finished = fn () => $this->f->finishedBatch(null, ['produced_qty' => '10', 'waste_qty' => '2']);
});

it('confirms a finished batch for a manager: N consume rows and one output row, the request done', function () {
    $batch = ($this->finished)();
    $batch->request->forceFill(['reason' => 'stock_out', 'open_stock_out_key' => 1])->save();

    $response = ($this->confirm)($batch)->assertOk();

    $response->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('request.status', 'done')
        ->assertJsonPath('confirmed_by.id', $this->f->manager->id)
        ->assertJsonPath('finished_by.id', $this->f->baker->id);
    $rows = ($this->movements)()->keyBy('item_id');
    expect($rows)->toHaveCount(3)
        ->and($rows[$this->f->flour->id]->qty)->toBe('-1200.0000')
        ->and($rows[$this->f->sugar->id]->qty)->toBe('-600.0000')
        ->and($rows[$this->f->cake->id]->qty)->toBe('10.0000')
        ->and($rows[$this->f->cake->id]->to_location_id)->toBe($this->f->display->id)
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$this->f->manager->id], 'the confirmer wrote the stock');
    $row = $batch->fresh();
    expect($row->status)->toBe(BatchStatus::Confirmed)
        ->and($row->confirmed_by_user_id)->toBe($this->f->manager->id)
        ->and($row->finished_by_user_id)->toBe($this->f->baker->id)
        ->and($row->confirmed_at->greaterThanOrEqualTo($row->finished_at))->toBeTrue()
        ->and($row->output_movement_id)->toBe($rows[$this->f->cake->id]->id)
        ->and($row->request->status)->toBe(RequestStatus::Done)
        ->and($row->request->done_at)->not->toBeNull()
        ->and($row->request->open_stock_out_key)->toBeNull();
    $oracle = ledgerOracle($this->branch->id);
    expect($oracle['balances'])->toBe($oracle['movements'])->toBe($oracle['view']);
    $audit = AuditLog::query()->where('action', 'production_batch.confirm')->where('subject_id', $batch->id)->sole();
    expect($audit->actor_id)->toBe($this->f->manager->id)->and($audit->before['status'])->toBe('finished')->and($audit->after['status'])->toBe('confirmed');
})->group('gate:p2');

it('lets the admin and the overseer flagged at the station confirm, and nobody else', function () {
    foreach ([$this->f->admin, $this->overseer] as $person) {
        $batch = ($this->finished)();
        loginAs($person);
        ($this->confirm)($batch)->assertOk()->assertJsonPath('confirmed_by.id', $person->id);
    }

    $batch = ($this->finished)();
    foreach ([$this->f->baker2, $this->f->breadBaker, $this->f->cashier, $this->f->chef, $this->f->waiter] as $person) {
        loginAs($person);
        ($this->confirm)($batch)->assertStatus(403)->assertJsonPath('message_key', $person->hasRole('waiter') ? 'auth.forbidden' : 'production.confirm.forbidden');
    }
    expect($batch->fresh()->status)->toBe(BatchStatus::Finished);
});

it('does not take the overseer flag of another station', function () {
    $other = makeUser($this->branch, 'bread_baker');
    UserStationAssignment::factory()->overseer()->create(['branch_id' => $this->branch->id, 'user_id' => $other->id, 'station_id' => $this->f->bakeryStation->id]);
    $batch = ($this->finished)();

    loginAs($other);
    ($this->confirm)($batch)->assertStatus(403)->assertJsonPath('message_key', 'production.confirm.forbidden');
});

it('refuses the person who pressed Done, even an overseer or an admin, and writes nothing', function () {
    foreach ([$this->overseer, $this->f->admin, $this->f->manager] as $person) {
        $batch = $this->f->finishedBatch($person);
        loginAs($person);

        ($this->confirm)($batch)->assertStatus(409)->assertJsonPath('message_key', 'production.batch.self_confirm');
        expect($batch->fresh()->status)->toBe(BatchStatus::Finished);
    }
    expect(($this->movements)())->toHaveCount(0);
});

it('refuses the person acting by short code as the finisher, and judges the rights of the person acting', function () {
    $device = Device::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Cake room screen']);
    $headers = fn (User $person): array => ['X-Acting-Session' => (string) app(ActingAsSession::class)->start($person, $device)->id, 'X-Device-Id' => $device->fingerprint];
    $screen = $this->f->manager;
    loginAs($screen);
    $own = $this->f->finishedBatch($this->overseer);

    $this->withHeaders(idempotencyKey() + $headers($this->overseer))->postJson("/api/v1/production/batches/{$own->id}/confirm", [])
        ->assertStatus(409)->assertJsonPath('message_key', 'production.batch.self_confirm');

    $other = ($this->finished)();
    $this->withHeaders(idempotencyKey() + $headers($this->f->baker2))->postJson("/api/v1/production/batches/{$other->id}/confirm", [])
        ->assertStatus(403)->assertJsonPath('message_key', 'production.confirm.forbidden');
    $this->withHeaders(idempotencyKey() + $headers($this->overseer))->postJson("/api/v1/production/batches/{$other->id}/confirm", [])
        ->assertOk()->assertJsonPath('confirmed_by.id', $this->overseer->id);
    expect(($this->movements)())->toHaveCount(3);
});

it('answers a repeat confirm 200 and writes no second set of rows, whoever repeats it', function () {
    $batch = ($this->finished)();
    ($this->confirm)($batch)->assertOk();
    $movements = DB::table('stock_movements')->count();
    $audits = AuditLog::query()->count();

    ($this->confirm)($batch)->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('confirmed_by.id', $this->f->manager->id);
    loginAs($this->f->admin);
    ($this->confirm)($batch)->assertOk()->assertJsonPath('confirmed_by.id', $this->f->manager->id);

    expect(DB::table('stock_movements')->count())->toBe($movements)->and(AuditLog::query()->count())->toBe($audits);
});

it('refuses a batch that is not finished: started, with the transition named', function () {
    $batch = $this->f->startedBatch();

    ($this->confirm)($batch)->assertStatus(409)
        ->assertJsonPath('message_key', 'state_machine.illegal_transition')
        ->assertJsonPath('from', 'started')
        ->assertJsonPath('to', 'confirmed');
    expect(($this->movements)())->toHaveCount(0);
});

it('answers 409 with the shortfall and keeps the batch finished when an ingredient is short at confirm time', function () {
    $batch = ($this->finished)();
    DB::table('stock_balances')->where('item_id', $this->f->sugar->id)->update(['on_hand' => '599.0000']);
    $oracle = ledgerOracle($this->branch->id);

    $response = ($this->confirm)($batch)->assertStatus(409);

    $response->assertJsonPath('message_key', 'production.batch.ingredient_short')
        ->assertJsonPath('shortfall.0.ingredient.id', $this->f->sugar->id)
        ->assertJsonPath('shortfall.0.needed', '600.0000')
        ->assertJsonPath('shortfall.0.available', '599.0000')
        ->assertJsonPath('shortfall.0.short', '1.0000')
        ->assertJsonPath('shortfall.0.location_id', $this->f->cakeRoom->id);
    expect($response->json('message_en'))->toContain('Sugar')->and($response->json('message_am'))->toContain('ስኳር');
    expect(($this->movements)())->toHaveCount(0)
        ->and(ledgerOracle($this->branch->id))->toBe($oracle)
        ->and($batch->fresh()->status)->toBe(BatchStatus::Finished)
        ->and($batch->fresh()->confirmed_by_user_id)->toBeNull()
        ->and($batch->request->fresh()->status)->toBe(RequestStatus::PendingConfirmation)
        ->and(AuditLog::query()->where('action', 'production_batch.confirm')->count())->toBe(0);

    DB::table('stock_balances')->where('item_id', $this->f->sugar->id)->update(['on_hand' => '600.0000']);
    ($this->confirm)($batch)->assertOk();
});

it('announces BatchDone confirmed after the commit, and nothing when refused', function () {
    Event::fake([BatchDone::class]);
    $refused = ($this->finished)();
    DB::table('stock_balances')->where('item_id', $this->f->sugar->id)->update(['on_hand' => '1.0000']);
    ($this->confirm)($refused)->assertStatus(409);
    Event::assertNotDispatched(BatchDone::class);

    DB::table('stock_balances')->where('item_id', $this->f->sugar->id)->update(['on_hand' => '5000.0000']);
    ($this->confirm)($refused)->assertOk();

    Event::assertDispatched(BatchDone::class, fn (BatchDone $e) => $e->status === 'confirmed' && $e->batchId === $refused->id
        && $e->finishedByUserId === $this->f->baker->id && $e->confirmedByUserId === $this->f->manager->id);
    Event::assertDispatchedTimes(BatchDone::class, 1);
});

it('is 404 for another branch batch', function () {
    $foreign = ProductionBatch::factory()->finished()->create(['branch_id' => Branch::factory()->create()->id]);

    ($this->confirm)($foreign)->assertStatus(404);
    expect(User::query()->count())->toBeGreaterThan(0);
});
