<?php

declare(strict_types=1);

use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Production\Contracts\Batches;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Exceptions\ProductionError;
use App\Shared\Models\Branch;
use App\Shared\StateMachine\IllegalTransition;
use App\Shared\Values\Quantity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\ProductionFixture;

pest()->group('module:production', 'feature');

/*
 * Production's Batches contract (manifest production.provides, step 2.7c): finish(batch, produced, waste) records what was made
 * and, where the station needs no overseer, confirms in the same write; confirm(batch, confirmer) is the overseer's sign-off.
 * The endpoints are thin callers of the same actions, so every rule of the endpoints holds here: the stock is written once, by
 * one Ledger::appendMany, with client uuids derived from the batch.
 */

beforeEach(function () {
    $this->branch = actInBranch(Branch::factory()->create());
    $this->f = ProductionFixture::build($this->branch);
    $this->f->stockCakeRoom();
    withActor($this->f->baker->id);
    $this->batches = fn () => app(Batches::class);
    $this->count = fn (): int => DB::table('stock_movements')->where('ref_type', 'production_batch')->count();
});

it('finishes and confirms in one write where no overseer is needed, by model or by id, with strings or quantities', function () {
    $batch = $this->f->startedBatch();

    $done = ($this->batches)()->finish($batch->id, '10', '2');

    expect($done->status)->toBe(BatchStatus::Confirmed)
        ->and((string) $done->produced_qty)->toBe('10.0000')
        ->and($done->confirmed_by_user_id)->toBe($this->f->baker->id)
        ->and($done->request->status)->toBe(RequestStatus::Done)
        ->and(($this->count)())->toBe(3);

    $second = $this->f->startedBatch();
    $again = ($this->batches)()->finish($second, Quantity::of('4'), Quantity::of('0'));
    expect($again->status)->toBe(BatchStatus::Confirmed)->and(($this->count)())->toBe(6);
});

it('finishes only, then a second person confirms: the finisher cannot, an unauthorised person cannot', function () {
    $this->f->requireOverseer();
    $batch = $this->f->startedBatch();

    $finished = ($this->batches)()->finish($batch, '12', '0');
    expect($finished->status)->toBe(BatchStatus::Finished)->and(($this->count)())->toBe(0);

    expect(fn () => ($this->batches)()->confirm($batch, $this->f->baker->id))->toThrow(ProductionError::class, 'production.batch.self_confirm');
    expect(fn () => ($this->batches)()->confirm($batch, $this->f->baker2->id))->toThrow(ProductionError::class, 'production.confirm.forbidden');

    withActor($this->f->manager->id);
    $confirmed = ($this->batches)()->confirm($batch->id, $this->f->manager->id);
    expect($confirmed->status)->toBe(BatchStatus::Confirmed)
        ->and($confirmed->confirmed_by_user_id)->toBe($this->f->manager->id)
        ->and(($this->count)())->toBe(3);

    expect(($this->batches)()->confirm($batch, $this->f->manager->id)->status)->toBe(BatchStatus::Confirmed)
        ->and(($this->count)())->toBe(3, 'a second confirm writes nothing');
});

it('refuses finish on a settled batch with other numbers, and confirm on a started one', function () {
    $batch = $this->f->startedBatch();
    expect(fn () => ($this->batches)()->confirm($batch, $this->f->manager->id))->toThrow(IllegalTransition::class);

    ($this->batches)()->finish($batch, '5', '1');
    expect(fn () => ($this->batches)()->finish($batch, '6', '1'))->toThrow(IllegalTransition::class);
});

it('rolls the whole write back when the last movement fails: no rows, no balances, the batch still started', function () {
    $batch = $this->f->startedBatch();
    // the output row's derived client uuid already belongs to a movement of this branch: its insert is the last and fails
    $outputUuid = Uuid::uuid5(Uuid::NAMESPACE_URL, "production-batch:{$this->branch->id}:{$batch->client_uuid}:output")->toString();
    app(Ledger::class)->append(MovementIntent::of($this->f->soda->id, $this->f->display->id, '1', MovementReason::PurchaseIn, 'test', null, null, $outputUuid));
    $movements = DB::table('stock_movements')->count();
    $oracle = ledgerOracle($this->branch->id);

    expect(fn () => ($this->batches)()->finish($batch, '10', '2'))->toThrow(UniqueConstraintViolationException::class);

    expect(DB::table('stock_movements')->count())->toBe($movements, 'neither consume row survived')
        ->and(ledgerOracle($this->branch->id))->toBe($oracle)
        ->and($batch->fresh()->status)->toBe(BatchStatus::Started)
        ->and($batch->fresh()->output_movement_id)->toBeNull()
        ->and($batch->request->fresh()->status)->toBe(RequestStatus::InProgress)
        ->and(DB::table('audit_log')->where('action', 'production_batch.done')->count())->toBe(0);
})->group('gate:p2');
