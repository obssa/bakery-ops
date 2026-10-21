<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Identity\Contracts\People;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Support\RequestAccess;
use App\Shared\Models\Station;
use App\Shared\StateMachine\IllegalTransition;
use Carbon\CarbonImmutable;

/**
 * POST /production/requests/{request}/accept (phase-2 plan section 4): a baker takes a request. The person must hold
 * production.fulfil at the request's station (403 production.station.forbidden; the admin and the manager work at every
 * station). A request assigned to someone else, or already accepted by someone else, is theirs: 409 production.request.taken.
 * Any status but `requested` and the person's own `accepted` is 409 state_machine.illegal_transition naming it. Accepting what
 * is already accepted by the same person is a repeat: it answers the request as it stands, writing nothing, not even an audit
 * row.
 *
 * Identity's ShiftAttacher is called for the person and the station (through People::attachShift; a no-op until Phase 3 binds
 * the real one) and what it answered goes in the audit context as shift_instance_id: null today. It is called before the write,
 * and only for a request that is not already accepted; the request row is then locked and its state checked again, so two
 * bakers racing for it have exactly one winner (the other is 409 taken) and one baker tapping twice writes once. One
 * AuditedAction (production_request.accept).
 */
final class AcceptRequest
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly RequestAccess $access,
        private readonly People $people,
    ) {}

    /**
     * @throws ProductionError auth.forbidden, production.station.forbidden (403), production.request.taken (409)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    public function handle(ProductionRequest $request): ProductionRequest
    {
        $person = $this->access->person('production.fulfil');
        $this->access->assertAtStation($person, $request);
        if ($this->settled($request, $person)) {
            return $request;
        }

        $station = Station::query()->withTrashed()->findOrFail($request->station_id);
        $shift = $this->people->attachShift($person, $station);

        try {
            return $this->audit->run(function (ProductionRequest $request) use ($person): ProductionRequest {
                RequestLock::refresh($request);
                if ($this->settled($request, $person)) {
                    throw AlreadyReached::here();
                }

                $request->forceFill([
                    'status' => $request->status->transitionTo(RequestStatus::Accepted),
                    'assigned_to_user_id' => $person,
                    'accepted_at' => CarbonImmutable::now()->startOfSecond(),
                    'version' => $request->version + 1,
                ])->save();

                return $request;
            }, $request, 'production_request.accept', ['shift_instance_id' => $shift]);
        } catch (AlreadyReached) {
            return ProductionRequest::query()->findOrFail($request->id);
        }
    }

    /**
     * True when the request is already accepted by this person (it answers itself); false when they may take it now.
     *
     * @throws ProductionError production.request.taken (409)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    private function settled(ProductionRequest $request, int $person): bool
    {
        return match ($request->status) {
            RequestStatus::Requested => $request->assigned_to_user_id !== null && $request->assigned_to_user_id !== $person
                ? throw ProductionError::taken()
                : false,
            RequestStatus::Accepted => $request->assigned_to_user_id === $person ? true : throw ProductionError::taken(),
            default => throw IllegalTransition::between($request->status, RequestStatus::Accepted),
        };
    }
}
