<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Support\RequestAccess;
use App\Shared\Events\BatchDone;
use App\Shared\Models\Station;
use App\Shared\StateMachine\IllegalTransition;
use Carbon\CarbonImmutable;

/**
 * POST /production/batches/{batch}/confirm and Batches::confirm (phase-2 plan section 4): where a station needs an overseer, the
 * finished batch waits for one. Who: production.confirm (admin, manager) or the overseer flagged at the batch's station (403
 * production.confirm.forbidden), and never the person who pressed Done (409 production.batch.self_confirm; the sign-off CHECK of
 * the table refuses it as well, so an admin working alone cannot confirm their own batch, open question 4). This is the one place
 * the stock of such a batch is written: BatchStock takes the N production_consume rows and the production_output row in one
 * Ledger::appendMany, after checking `available` under the ledger's locks; a short ingredient is 409
 * production.batch.ingredient_short, nothing is written, and the batch stays finished.
 * Confirming a confirmed batch is a repeat: 200, nothing written, whoever repeats it. A started batch is 409
 * state_machine.illegal_transition. The request becomes done. One AuditedAction (production_batch.confirm) under the request and
 * batch row locks, so two confirms at the same moment write one set of movements.
 */
final class ConfirmBatch
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly RequestAccess $access,
        private readonly BatchStock $stock,
        private readonly RequestAnnouncer $announcer,
    ) {}

    /**
     * @param  int  $confirmer  the person who signs off
     *
     * @throws ProductionError production.confirm.forbidden (403), production.batch.self_confirm, production.batch.ingredient_short (409)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    public function handle(ProductionBatch $batch, int $confirmer): ProductionBatch
    {
        $station = Station::query()->withTrashed()->findOrFail($batch->station_id);
        if ($this->settled($batch, $confirmer, $station)) {
            return $batch;
        }

        try {
            return $this->audit->run(function (ProductionBatch $batch) use ($confirmer, $station): ProductionBatch {
                $request = BatchLock::refresh($batch);
                if ($this->settled($batch, $confirmer, $station)) {
                    throw AlreadyReached::here();
                }

                $now = CarbonImmutable::now()->startOfSecond();
                $produced = $batch->produced_qty;
                $waste = $batch->waste_qty;
                if ($produced === null || $waste === null) {
                    throw IllegalTransition::between($batch->status, BatchStatus::Confirmed);
                }
                $outputMovementId = $this->stock->write($batch, $request->product_item_id, $produced, $waste);
                $batch->forceFill([
                    'status' => $batch->status->transitionTo(BatchStatus::Confirmed),
                    'confirmed_at' => $now,
                    'confirmed_by_user_id' => $confirmer,
                    'output_movement_id' => $outputMovementId,
                    'version' => $batch->version + 1,
                ])->save();
                $request->forceFill([
                    'status' => $request->status->transitionTo(RequestStatus::Done),
                    'done_at' => $now,
                    'open_stock_out_key' => null,
                    'version' => $request->version + 1,
                ])->save();
                $this->announcer->batchDone($request, $batch, BatchDone::CONFIRMED);

                return $batch;
            }, $batch, 'production_batch.confirm');
        } catch (AlreadyReached) {
            return ProductionBatch::query()->findOrFail($batch->id);
        }
    }

    /**
     * True when the batch is already confirmed (it answers itself, to someone who may confirm); false when it can be confirmed now.
     *
     * @throws ProductionError production.confirm.forbidden (403), production.batch.self_confirm (409)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    private function settled(ProductionBatch $batch, int $confirmer, Station $station): bool
    {
        if ($batch->status === BatchStatus::Confirmed) {
            $this->access->assertMayConfirm($confirmer, $station);

            return true;
        }
        if ($batch->status === BatchStatus::Started) {
            throw IllegalTransition::between($batch->status, BatchStatus::Confirmed);
        }
        if ($batch->finished_by_user_id === $confirmer) {
            throw ProductionError::selfConfirm();
        }
        $this->access->assertMayConfirm($confirmer, $station);

        return false;
    }
}
