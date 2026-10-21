<?php

declare(strict_types=1);

namespace App\Modules\Production\Http\Controllers;

use App\Modules\Production\Actions\ConfirmBatch;
use App\Modules\Production\Actions\FinishBatch;
use App\Modules\Production\Actions\SendBackBatch;
use App\Modules\Production\Actions\StartBatch;
use App\Modules\Production\Http\Requests\DoneBatchRequest;
use App\Modules\Production\Http\Requests\SendBackBatchRequest;
use App\Modules\Production\Http\Requests\StartBatchRequest;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Support\RequestAccess;
use App\Modules\Production\Support\RequestPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Production batches (phase-2 plan section 4, step 2.7c). Every write is an AuditedAction in the action classes behind an
 * Idempotency-Key; another branch's batch or request is 404 through the BranchScoped route binding (rule 8). The route parameters
 * are {request} (Start) and {batch}.
 *
 *   POST /production/requests/{request}/start   200 the ProductionRequest, in_progress, with its started batch (production.fulfil at
 *                                               the station, the assignee); a repeat of the batch client_uuid answers the same
 *   POST /production/batches/{batch}/done       200 the Batch: confirmed (and the request done, the stock written) where the station
 *                                               needs no overseer, finished (the request pending_confirmation) where it does;
 *                                               409 production.batch.ingredient_short writes nothing
 *   POST /production/batches/{batch}/confirm    200 the Batch, confirmed, the request done, the stock written (production.confirm or the
 *                                               overseer of the station; never the person who finished it)
 *   POST /production/batches/{batch}/send-back  200 the Batch, back to started with the note, the request in_progress (same people)
 * A Batch answer is the batch as the request resource carries it plus `request_id` and `request {id, status, version}`.
 */
final class BatchesController
{
    public function __construct(
        private readonly RequestPresenter $presenter,
        private readonly RequestAccess $access,
    ) {}

    public function start(StartBatchRequest $input, ProductionRequest $request, StartBatch $start): JsonResponse
    {
        $started = $start->handle($request, $input->clientUuid());

        return response()->json($this->presenter->one(ProductionRequest::query()->findOrFail($started->id)));
    }

    public function done(DoneBatchRequest $input, ProductionBatch $batch, FinishBatch $finish): JsonResponse
    {
        return $this->answer($finish->handle($batch, $input->produced(), $input->waste()));
    }

    public function confirm(Request $input, ProductionBatch $batch, ConfirmBatch $confirm): JsonResponse
    {
        return $this->answer($confirm->handle($batch, $this->access->person('production.view')));
    }

    public function sendBack(SendBackBatchRequest $input, ProductionBatch $batch, SendBackBatch $sendBack): JsonResponse
    {
        return $this->answer($sendBack->handle($batch, $this->access->person('production.view'), $input->note()));
    }

    private function answer(ProductionBatch $batch): JsonResponse
    {
        return response()->json($this->presenter->batch(ProductionBatch::query()->with('request')->findOrFail($batch->id)));
    }
}
