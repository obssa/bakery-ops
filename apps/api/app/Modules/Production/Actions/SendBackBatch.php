<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Support\RequestAccess;
use App\Shared\Models\Station;
use App\Shared\StateMachine\IllegalTransition;

/**
 * POST /production/batches/{batch}/send-back (phase-2 plan section 4): an overseer who does not accept the numbers sends the finished
 * batch back to the baker with a note (open question 5: there is no editing of the numbers by the overseer). The same people may
 * as may confirm (production.confirm, or the overseer flagged at the station; 403 production.confirm.forbidden); the person who
 * pressed Done is not excluded, they would only be sending their own work back. The batch returns to started with send_back_count
 * + 1 and the note, the request to in_progress; produced, waste and the finished columns stay for the card (the table's CHECK allows
 * it) and Done overwrites them. Nothing is in the ledger at that point. Only a finished batch can be sent back: 409
 * state_machine.illegal_transition otherwise. One AuditedAction (production_batch.send_back) under the request and batch row locks.
 */
final class SendBackBatch
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly RequestAccess $access,
    ) {}

    /**
     * @throws ProductionError production.confirm.forbidden (403)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    public function handle(ProductionBatch $batch, int $person, string $note): ProductionBatch
    {
        $station = Station::query()->withTrashed()->findOrFail($batch->station_id);
        $this->access->assertMayConfirm($person, $station);
        $batch->status->transitionTo(BatchStatus::Started);

        return $this->audit->run(function (ProductionBatch $batch) use ($note): ProductionBatch {
            $request = BatchLock::refresh($batch);

            $batch->forceFill([
                'status' => $batch->status->transitionTo(BatchStatus::Started),
                'send_back_note' => $note,
                'send_back_count' => $batch->send_back_count + 1,
                'version' => $batch->version + 1,
            ])->save();
            $request->forceFill([
                'status' => $request->status->transitionTo(RequestStatus::InProgress),
                'version' => $request->version + 1,
            ])->save();

            return $batch;
        }, $batch, 'production_batch.send_back');
    }
}
