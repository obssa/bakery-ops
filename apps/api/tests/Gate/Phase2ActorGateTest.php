<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductionFixture;

/*
 * Phase 2 gate, the actor of queued work (ADR 0011; rule 4): a listener runs without a request, so it restores the stamp of the
 * person whose movement caused it (ActorContext::runAs) and no system user exists. Every row the chain writes after a cashier
 * ran the cake out says so: the episode, the automatic request and its audit row name the cashier; the inbox lines carry the
 * cashier as `payload.caused_by_user_id`; and when the baker then makes the batch, the rows of that second chain name the baker.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    $this->f->stockCakeRoom('20000', '20000');
});

it('stamps the rows queued work writes with the person who caused them, never a system user', function () {
    $f = $this->f;
    withActor($f->cashier->id);
    stockIn($f->cake, $f->display, '1');
    stockIn($f->cake, $f->display, '-1', MovementReason::Waste);

    $request = ProductionRequest::query()->sole();
    $episode = DB::table('stock_alert_states')->where('item_id', $f->cake->id)->where('type', 'out')->sole();
    expect($episode->caused_by_user_id)->toBe($f->cashier->id)
        ->and($request->requested_by_user_id)->toBe($f->cashier->id)
        ->and($request->created_by_user_id)->toBe($f->cashier->id)
        ->and(AuditLog::query()->where('action', 'production_request.auto_create')->where('subject_id', $request->id)->sole()->actor_id)->toBe($f->cashier->id);
    $lines = Notification::query()->whereIn('type', ['stock_out', 'production_requested'])->get();
    expect($lines)->not->toBeEmpty()
        ->and($lines->every(fn (Notification $n): bool => ($n->payload['caused_by_user_id'] ?? null) === $f->cashier->id))->toBeTrue('every inbox line of the first chain names the cashier');

    // the baker's chain: accept, start, Done on a station without an overseer
    $post = fn ($person, string $uri, array $body = []) => tap($this->withHeaders(idempotencyKey()), fn () => loginAs($person))->postJson($uri, $body);
    $post($f->baker, "/api/v1/production/requests/{$request->id}/accept")->assertOk();
    $post($f->baker, "/api/v1/production/requests/{$request->id}/start", ['client_uuid' => (string) Str::uuid()])->assertOk();
    $batch = ProductionBatch::query()->sole();
    $post($f->baker, "/api/v1/production/batches/{$batch->id}/done", ['produced_qty' => '12', 'waste_qty' => '0'])->assertOk()->assertJsonPath('status', 'confirmed');

    $rows = DB::table('stock_movements')->where('ref_type', 'production_batch')->where('ref_id', $batch->id)->get();
    expect($rows)->toHaveCount(3)->and($rows->pluck('user_id')->unique()->all())->toBe([$f->baker->id])
        ->and(Notification::query()->where('type', 'batch_done')->get()->every(fn (Notification $n): bool => ($n->payload['caused_by_user_id'] ?? null) === $f->baker->id))->toBeTrue()
        ->and(DB::table('audit_log')->whereNull('actor_id')->count())->toBe(0, 'no audit row of the chain is written by nobody');
})->group('gate:p2', 'feature', 'module:production');
