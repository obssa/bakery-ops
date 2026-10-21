<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Support\RequestAccess;
use App\Shared\Events\BatchDone;
use App\Shared\Models\Station;
use App\Shared\StateMachine\IllegalTransition;
use App\Shared\Values\Quantity;
use Carbon\CarbonImmutable;

/**
 * POST /production/batches/{batch}/done and Batches::finish (phase-2 plan section 4): the baker says how many were made and how many
 * were wasted (both never negative, the sum above zero). Who: production.fulfil at the station, and the request's assignee.
 *   - Where the station needs no overseer, Done is the whole of it: the batch is finished AND confirmed in this one write
 *     (confirmed_at = finished_at, the one case the sign-off CHECK lets the same person confirm), the request is done and its
 *     stock-out key is freed, and BatchStock writes the movements (N production_consume and one production_output, one
 *     Ledger::appendMany, all or nothing). A short ingredient is 409 production.batch.ingredient_short and nothing is written:
 *     the batch stays started and the baker can transfer stock in and press Done again.
 *   - Where it does, the batch is finished, the request pending_confirmation, nothing is written yet (the shortage, if any, is found
 *     when the overseer confirms), and BatchDone(pending_confirmation) asks the overseers.
 * A Done repeated with the same numbers on a batch that is already finished or confirmed is a repeat: 200, nothing written; other
 * numbers are 409 state_machine.illegal_transition. One AuditedAction (production_batch.done) under the request and batch row locks,
 * so two taps write once.
 */
final class FinishBatch
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly RequestAccess $access,
        private readonly BatchStock $stock,
        private readonly RequestAnnouncer $announcer,
    ) {}

    /**
     * @throws ProductionError auth.forbidden, production.station.forbidden (403), production.request.taken, production.batch.ingredient_short (409)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    public function handle(ProductionBatch $batch, Quantity $produced, Quantity $waste): ProductionBatch
    {
        $request = $batch->request;
        $person = $this->access->person('production.fulfil');
        $this->access->assertAtStation($person, $request);
        if ($request->assigned_to_user_id !== $person) {
            throw ProductionError::taken();
        }
        if (self::settled($batch, $produced, $waste)) {
            return $batch;
        }
        $station = Station::query()->withTrashed()->findOrFail($batch->station_id);

        try {
            return $this->audit->run(function (ProductionBatch $batch) use ($person, $produced, $waste, $station): ProductionBatch {
                $request = BatchLock::refresh($batch);
                if (self::settled($batch, $produced, $waste)) {
                    throw AlreadyReached::here();
                }

                $now = CarbonImmutable::now()->startOfSecond();
                $finished = $batch->status->transitionTo(BatchStatus::Finished);
                $batch->forceFill([
                    'status' => $finished,
                    'produced_qty' => $produced,
                    'waste_qty' => $waste,
                    'finished_at' => $now,
                    'finished_by_user_id' => $person,
                    'version' => $batch->version + 1,
                ]);

                if ($station->requires_overseer) {
                    $request->forceFill([
                        'status' => $request->status->transitionTo(RequestStatus::PendingConfirmation),
                        'version' => $request->version + 1,
                    ])->save();
                    $batch->save();
                    $this->announcer->batchDone($request, $batch, BatchDone::PENDING);

                    return $batch;
                }

                $outputMovementId = $this->stock->write($batch, $request->product_item_id, $produced, $waste);
                $batch->forceFill([
                    'status' => $finished->transitionTo(BatchStatus::Confirmed),
                    'confirmed_at' => $now,
                    'confirmed_by_user_id' => $person,
                    'output_movement_id' => $outputMovementId,
                ])->save();
                $this->closeRequest($request, $now);
                $this->announcer->batchDone($request, $batch, BatchDone::CONFIRMED);

                return $batch;
            }, $batch, 'production_batch.done');
        } catch (AlreadyReached) {
            return ProductionBatch::query()->findOrFail($batch->id);
        }
    }

    /**
     * The request is done: the work it asked for is in the ledger, and its stock-out key is free for the next run-out.
     */
    private function closeRequest(ProductionRequest $request, CarbonImmutable $at): void
    {
        $request->forceFill([
            'status' => $request->status->transitionTo(RequestStatus::Done),
            'done_at' => $at,
            'open_stock_out_key' => null,
            'version' => $request->version + 1,
        ])->save();
    }

    /**
     * True when the batch already holds these numbers as finished or confirmed (it answers itself); false when it can be finished now.
     *
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    private static function settled(ProductionBatch $batch, Quantity $produced, Quantity $waste): bool
    {
        if ($batch->status === BatchStatus::Started) {
            return false;
        }
        if ($batch->produced_qty?->equals($produced) === true && $batch->waste_qty?->equals($waste) === true) {
            return true;
        }

        throw IllegalTransition::between($batch->status, BatchStatus::Finished);
    }
}
