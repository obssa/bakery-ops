<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Http\Requests\ListTransfersRequest;
use App\Modules\Inventory\Http\Requests\RejectTransferRequest;
use App\Modules\Inventory\Http\Requests\StoreTransferRequest;
use App\Modules\Inventory\Http\Resources\TransferResource;
use App\Modules\Inventory\Models\Transfer;
use App\Modules\Inventory\Transfers\ConfirmTransfer;
use App\Modules\Inventory\Transfers\RejectTransfer;
use App\Modules\Inventory\Transfers\RequestTransfer;
use App\Shared\Actor\ActorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Transfers (phase-2 plan section 4, step 2.3). Every write is an AuditedAction in the action classes behind an
 * Idempotency-Key; another branch's transfer is 404 through the BranchScoped route binding (rule 8).
 *
 *   GET  /transfers                       200 {data: [Transfer], links, meta}, newest first (inventory.view);
 *                                         ?status=, ?location_id= (either end), ?mine=1, ?production_request_id=
 *   POST /transfers                       201 the requested Transfer; 200 the same transfer for a client_uuid already used
 *                                         (transfers.request, and the requester works at the destination's station)
 *   POST /transfers/{transfer}/confirm    200 the confirmed Transfer with both movement ids (a confirmed one answers itself and
 *                                         writes nothing); 409 inventory.transfer.insufficient {available, requested} with the
 *                                         row still requested; 409 inventory.transfer.rejected; 403 ...source_forbidden
 *   POST /transfers/{transfer}/reject     200 the rejected Transfer {reason required} (a rejected one answers itself);
 *                                         409 inventory.transfer.confirmed
 * transfers.confirm covers both decisions, and the person must work at the source's station (or at every station).
 */
final class TransfersController
{
    public function __construct(private readonly ActorContext $actor) {}

    public function index(ListTransfersRequest $request): AnonymousResourceCollection
    {
        $query = Transfer::query()
            ->with(['item.unit.baseUnit', 'fromLocation', 'toLocation', 'requestedBy', 'decidedBy'])
            ->orderByDesc('requested_at')
            ->orderByDesc('id');
        if ($request->status() !== null) {
            $query->where('status', $request->status()->value);
        }
        if ($request->locationId() !== null) {
            $locationId = $request->locationId();
            $query->where(fn ($q) => $q->where('from_location_id', $locationId)->orWhere('to_location_id', $locationId));
        }
        if ($request->productionRequestId() !== null) {
            $query->where('production_request_id', $request->productionRequestId());
        }
        if ($request->mine()) {
            $query->where('requested_by_user_id', $this->actor->effectiveUserId());
        }

        return TransferResource::collection($query->paginate($request->perPage()));
    }

    public function store(StoreTransferRequest $request, RequestTransfer $requestTransfer): JsonResponse
    {
        [$transfer, $created] = $requestTransfer->handle(
            $request->clientUuid(),
            $request->itemId(),
            $request->toLocationId(),
            $request->qty(),
            $request->unitId(),
            $request->fromLocationId(),
            $request->note(),
            $request->productionRequestId(),
        );

        return $this->answer($request, $transfer, $created ? 201 : 200);
    }

    public function confirm(Request $request, Transfer $transfer, ConfirmTransfer $confirm): JsonResponse
    {
        return $this->answer($request, $confirm->handle($transfer), 200);
    }

    public function reject(RejectTransferRequest $request, Transfer $transfer, RejectTransfer $reject): JsonResponse
    {
        return $this->answer($request, $reject->handle($transfer, $request->reason()), 200);
    }

    private function answer(Request $request, Transfer $transfer, int $status): JsonResponse
    {
        $transfer = Transfer::query()->findOrFail($transfer->id);

        return response()->json((new TransferResource(TransferResource::load($transfer)))->resolve($request), $status);
    }
}
