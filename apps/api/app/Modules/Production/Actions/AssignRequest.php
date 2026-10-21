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
 * (422 production.assignee.invalid). Only a request nobody has taken yet (`requested`) can be assigned, to someone else again as
 * often as needed; any other status is 409 state_machine.illegal_transition naming it. The status does not change: the baker
 * accepts it later (AcceptRequest). Assigning the person already named is a repeat: it answers the request as it stands,
 * writing and announcing nothing. One AuditedAction (production_request.assign) under the request row lock, then
 * ProductionAssigned after commit.
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
        if ($request->status === RequestStatus::Requested && $request->assigned_to_user_id === $userId) {
            return $request;
        }
        if (! $this->access->mayFulfil($userId, $request)) {
            throw ProductionError::assigneeInvalid($userId);
        }

        try {
            return $this->audit->run(function (ProductionRequest $request) use ($userId, $planner): ProductionRequest {
                RequestLock::refresh($request);
                if ($request->status !== RequestStatus::Requested) {
                    throw IllegalTransition::between($request->status, RequestStatus::Requested);
                }
                if ($request->assigned_to_user_id === $userId) {
                    throw AlreadyReached::here();
                }

                $request->forceFill(['assigned_to_user_id' => $userId, 'version' => $request->version + 1])->save();
                $this->announcer->assigned($request, $planner);

                return $request;
            }, $request, 'production_request.assign');
        } catch (AlreadyReached) {
            return ProductionRequest::query()->findOrFail($request->id);
        }
    }
}
