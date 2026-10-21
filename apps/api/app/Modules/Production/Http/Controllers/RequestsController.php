<?php

declare(strict_types=1);

namespace App\Modules\Production\Http\Controllers;

use App\Modules\Production\Actions\AcceptRequest;
use App\Modules\Production\Actions\AssignRequest;
use App\Modules\Production\Actions\CancelRequest;
use App\Modules\Production\Actions\CreateRequest;
use App\Modules\Production\Actions\NewRequest;
use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Http\Requests\AssignProductionRequest;
use App\Modules\Production\Http\Requests\CancelProductionRequest;
use App\Modules\Production\Http\Requests\ListProductionRequests;
use App\Modules\Production\Http\Requests\StoreProductionRequest;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Support\RequestBoard;
use App\Modules\Production\Support\RequestCard;
use App\Modules\Production\Support\RequestPresenter;
use App\Shared\Actor\ActorContext;
use App\Shared\Live\ConditionalPoll;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production requests (phase-2 plan section 4, step 2.7b). Every write is an AuditedAction in the action classes behind an
 * Idempotency-Key; another branch's request is 404 through the BranchScoped route binding (rule 8). The route parameter is
 * {request}, so the bound model is $request in every method and the validated input is $input.
 *
 *   GET  /production/requests                    200 {data: [ProductionRequest], counts, server_time} + ETag, or 304 (production.view);
 *                                                ?view=board, ?status=, ?station_id=, ?product_item_id=, ?mine=1, ?limit=
 *   GET  /production/requests/{request}          200 the ProductionRequest and its card: recipe (scaled), consume_location, output_location
 *   POST /production/requests                    201 the requested ProductionRequest; 200 the same request for a client_uuid already used
 *                                                (production.request; a due time or a priority other than normal needs production.plan)
 *   POST /production/requests/{request}/assign   200 the request with its baker (production.plan); 422 production.assignee.invalid
 *   POST /production/requests/{request}/accept   200 the accepted request, the caller its baker (production.fulfil at the request's station)
 *   POST /production/requests/{request}/cancel   200 the cancelled request (a planner, or the person who asked)
 * A repeated accept, assign or cancel answers 200 and writes nothing; the opposite transition is 409 naming the status.
 */
final class RequestsController
{
    public function __construct(
        private readonly ActorContext $actor,
        private readonly RequestPresenter $presenter,
    ) {}

    public function index(ListProductionRequests $input, RequestBoard $board): Response
    {
        $now = CarbonImmutable::now();
        $personId = $this->actor->effectiveUserId();
        $filter = [
            'board' => $input->board(),
            'status' => $input->status(),
            'station' => $input->stationId(),
            'product' => $input->productId(),
            'mine' => $input->mine(),
            'limit' => $input->limit(),
        ];

        return ConditionalPoll::respond($input, $board->etag($filter, $personId, $now), fn (): array => [
            'data' => $this->presenter->many($board->requests($filter, $personId, $now)),
            'counts' => $board->counts($filter, $personId, $now),
            'server_time' => $now->toIso8601String(),
        ]);
    }

    public function show(Request $input, ProductionRequest $request, RequestCard $card): JsonResponse
    {
        return response()->json($this->presenter->one($request) + $card->build($request));
    }

    public function store(StoreProductionRequest $input, CreateRequest $create): JsonResponse
    {
        $due = $input->dueAt();
        [$request, $created] = $create->handle(new NewRequest(
            clientUuid: $input->clientUuid(),
            product: $input->productId(),
            qty: $input->qty(),
            due: $due,
            reason: $due === null ? RequestReason::Manual : RequestReason::Schedule,
            priority: $input->priority(),
            note: $input->note(),
        ));

        return $this->answer($request, $created ? 201 : 200);
    }

    public function assign(AssignProductionRequest $input, ProductionRequest $request, AssignRequest $assign): JsonResponse
    {
        return $this->answer($assign->handle($request, $input->userId()), 200);
    }

    public function accept(Request $input, ProductionRequest $request, AcceptRequest $accept): JsonResponse
    {
        return $this->answer($accept->handle($request), 200);
    }

    public function cancel(CancelProductionRequest $input, ProductionRequest $request, CancelRequest $cancel): JsonResponse
    {
        return $this->answer($cancel->handle($request, $input->reason()), 200);
    }

    private function answer(ProductionRequest $request, int $status): JsonResponse
    {
        return response()->json($this->presenter->one(ProductionRequest::query()->findOrFail($request->id)), $status);
    }
}
