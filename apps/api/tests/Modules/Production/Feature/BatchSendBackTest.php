<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\UserStationAssignment;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Shared\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * POST /production/batches/{batch}/send-back (step 2.7c; phase-2 plan section 4): an overseer (production.confirm, or the
 * overseer flagged at the station) who does not accept the numbers sends the batch back to the baker with a note instead of
 * correcting them (open question 5: the default stands). The batch returns to started with send_back_count + 1 and the note
 * on the card; the request returns to in_progress. The produced, waste and finished columns stay (the 2.7a CHECK allows
 * it) so the card can show what was sent back, and Done overwrites them. Nothing is ever in the ledger at this point.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    $this->f->stockCakeRoom();
    $this->f->requireOverseer();
    loginAs($this->f->manager);
    $this->back = fn (ProductionBatch $batch, array $body = ['note' => 'Count again, the tray looked short']) => $this->withHeaders(idempotencyKey())
        ->postJson("/api/v1/production/batches/{$batch->id}/send-back", $body);
});

it('sends a finished batch back to the baker with the note, keeping what was recorded', function () {
    $batch = $this->f->finishedBatch(null, ['produced_qty' => '10', 'waste_qty' => '2']);

    $response = ($this->back)($batch)->assertOk();

    $response->assertJsonPath('status', 'started')
        ->assertJsonPath('send_back_note', 'Count again, the tray looked short')
        ->assertJsonPath('send_back_count', 1)
        ->assertJsonPath('produced_qty', '10.0000')
        ->assertJsonPath('request.status', 'in_progress');
    $row = $batch->fresh();
    expect($row->status)->toBe(BatchStatus::Started)
        ->and($row->send_back_count)->toBe(1)
        ->and((string) $row->waste_qty)->toBe('2.0000')
        ->and($row->finished_by_user_id)->toBe($this->f->baker->id)
        ->and($row->confirmed_at)->toBeNull()
        ->and($row->version)->toBe($batch->version + 1)
        ->and($row->request->fresh()->status)->toBe(RequestStatus::InProgress)
        ->and(DB::table('stock_movements')->where('ref_type', 'production_batch')->count())->toBe(0);
    $audit = AuditLog::query()->where('action', 'production_batch.send_back')->sole();
    expect($audit->actor_id)->toBe($this->f->manager->id)->and($audit->before['status'])->toBe('finished')->and($audit->after['status'])->toBe('started');
});

it('lets the baker redo it: Done again, a second send back counts twice, then the overseer confirms', function () {
    $batch = $this->f->startedBatch();
    $done = fn (string $produced) => $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/batches/{$batch->id}/done", ['produced_qty' => $produced, 'waste_qty' => '0']);

    loginAs($this->f->baker);
    $done('9')->assertOk()->assertJsonPath('status', 'finished');
    loginAs($this->f->manager);
    ($this->back)($batch, ['note' => 'first'])->assertOk()->assertJsonPath('send_back_count', 1);
    loginAs($this->f->baker);
    $done('11')->assertOk()->assertJsonPath('status', 'finished')->assertJsonPath('produced_qty', '11.0000');
    loginAs($this->f->manager);
    ($this->back)($batch, ['note' => 'second'])->assertOk()->assertJsonPath('send_back_count', 2)->assertJsonPath('send_back_note', 'second');
    loginAs($this->f->baker);
    $done('12')->assertOk();
    loginAs($this->f->manager);
    $this->withHeaders(idempotencyKey())->postJson("/api/v1/production/batches/{$batch->id}/confirm", [])->assertOk()->assertJsonPath('status', 'confirmed');

    $rows = DB::table('stock_movements')->where('ref_type', 'production_batch')->get();
    expect($rows)->toHaveCount(3)->and($rows->firstWhere('item_id', $this->f->cake->id)->qty)->toBe('12.0000', 'only the last numbers reach the ledger');
});

it('needs the same right as confirm: production.confirm or the overseer flag at the station', function () {
    $overseer = makeUser($this->branch, 'cake_baker');
    UserStationAssignment::factory()->overseer()->create(['branch_id' => $this->branch->id, 'user_id' => $overseer->id, 'station_id' => $this->f->cakeRoomStation->id]);
    foreach ([$this->f->admin, $overseer] as $person) {
        $batch = $this->f->finishedBatch();
        loginAs($person);
        ($this->back)($batch)->assertOk()->assertJsonPath('status', 'started');
    }

    $batch = $this->f->finishedBatch();
    foreach ([$this->f->baker2, $this->f->breadBaker, $this->f->cashier, $this->f->chef] as $person) {
        loginAs($person);
        ($this->back)($batch)->assertStatus(403)->assertJsonPath('message_key', 'production.confirm.forbidden');
    }
    loginAs($this->f->waiter);
    ($this->back)($batch)->assertStatus(403)->assertJsonPath('message_key', 'auth.forbidden');
    expect($batch->fresh()->status)->toBe(BatchStatus::Finished);
});

it('requires a note of at most 255 characters', function () {
    $batch = $this->f->finishedBatch();

    foreach ([[], ['note' => ''], ['note' => '   '], ['note' => null], ['note' => str_repeat('x', 256)], ['note' => ['a']]] as $body) {
        ($this->back)($batch, $body)->assertStatus(422)->assertJsonPath('message_key', 'validation.failed');
    }
    ($this->back)($batch, ['note' => str_repeat('x', 255)])->assertOk();
});

it('refuses a batch that is not finished, naming the transition', function () {
    $started = $this->f->startedBatch();
    ($this->back)($started)->assertStatus(409)
        ->assertJsonPath('message_key', 'state_machine.illegal_transition')
        ->assertJsonPath('from', 'started')
        ->assertJsonPath('to', 'started');

    $confirmed = ProductionBatch::factory()->confirmed()->create(['branch_id' => $this->branch->id]);
    ($this->back)($confirmed)->assertStatus(409)->assertJsonPath('from', 'confirmed');
    expect($started->fresh()->send_back_count)->toBe(0);
});

it('is 404 for another branch batch', function () {
    $foreign = ProductionBatch::factory()->finished()->create(['branch_id' => Branch::factory()->create()->id]);

    ($this->back)($foreign)->assertStatus(404);
});
