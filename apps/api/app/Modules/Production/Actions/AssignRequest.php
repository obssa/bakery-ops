<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Support\RequestAccess;
use App\Shared\StateMachine\IllegalTransition;

/**
 * POST /production/requests/{request}/assign (phase-2 plan section 4): a planner (production.plan) names the baker who makes a
 * request. The baker must be an active person of this branch who holds production.fulfil at the request's station
 * (422 production.assignee.invalid). A request nobody has taken yet (`requested`) can be assigned to someone else again as often
 * as needed, and its status does not change: the baker accepts it later (AcceptRequest). A request a baker accepted but did not
 * start (`accepted`) can be taken back and given to someone else (Ramin 2026-11-11: an accepted request nobody starts must not wait
 * forever): it goes back to `requested` with the new baker named and its acceptance cleared (audited production_request.reassign).
 * Any other status is 409 state_machine.illegal_transition naming it. Naming the person already named (or the one who accepted
 * it) is a repeat: it answers the request as it stands, writing and announcing nothing. One AuditedAction
 * (production_request.assign, or .reassign for an accepted one) under the request row lock, then ProductionAssigned after commit.
 */
final class AssignRequest
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly RequestAccess $access,
        private readonly RequestAnnouncer $announcer,
    ) {}

    /**
     * @throws ProductionError production.assignee.invalid (422), auth.forbidden (403)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    public function handle(ProductionRequest $request, int $userId): ProductionRequest
    {
        $planner = $this->access->person('production.plan');
        if (in_array($request->status, [RequestStatus::Requested, RequestStatus::Accepted], true) && $request->assigned_to_user_id === $userId) {
            return $request;
        }
        if (! $this->access->mayFulfil($userId, $request)) {
            throw ProductionError::assigneeInvalid($userId);
        }
        $action = $request->status === RequestStatus::Accepted ? 'production_request.reassign' : 'production_request.assign';

        try {
            return $this->audit->run(function (ProductionRequest $request) use ($userId, $planner): ProductionRequest {
                RequestLock::refresh($request);
                if (! in_array($request->status, [RequestStatus::Requested, RequestStatus::Accepted], true)) {
                    throw IllegalTransition::between($request->status, RequestStatus::Requested);
                }
                if ($request->assigned_to_user_id === $userId) {
                    throw AlreadyReached::here();
                }

                $fields = ['assigned_to_user_id' => $userId, 'version' => $request->version + 1];
                if ($request->status === RequestStatus::Accepted) {
                    // taken back from the baker who accepted it: requested again, for the new baker to accept
                    $fields['status'] = $request->status->transitionTo(RequestStatus::Requested);
                    $fields['accepted_at'] = null;
                }
                $request->forceFill($fields)->save();
                $this->announcer->assigned($request, $planner);

                return $request;
            }, $request, $action);
        } catch (AlreadyReached) {
            return ProductionRequest::query()->findOrFail($request->id);
        }
    }
}
