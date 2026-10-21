<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Support\RequestAccess;
use App\Shared\StateMachine\IllegalTransition;
use Carbon\CarbonImmutable;

/**
 * POST /production/requests/{request}/cancel (phase-2 plan section 4): a planner (production.plan) cancels any request that has
 * not started, and the person who asked cancels their own (403 production.cancel.forbidden for anyone else). A request whose
 * batch is started or finished is finished or sent back, never cancelled: 409 production.request.in_progress. A done request is
 * 409 state_machine.illegal_transition naming it. Cancelling what is cancelled is a repeat: it answers the request as it stands,
 * writing nothing (the first reason stays). The stock-out key is released in the same write, so the next run-out of the product
 * asks again. One AuditedAction (production_request.cancel) under the request row lock.
 */
final class CancelRequest
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly RequestAccess $access,
    ) {}

    /**
     * @throws ProductionError auth.forbidden, production.cancel.forbidden (403), production.request.in_progress (409)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    public function handle(ProductionRequest $request, ?string $reason): ProductionRequest
    {
        $person = $this->access->person('production.view');
        if (! $this->access->mayCancel($person, $request)) {
            throw ProductionError::cancelForbidden();
        }
        if (self::settled($request)) {
            return $request;
        }

        try {
            return $this->audit->run(function (ProductionRequest $request) use ($reason): ProductionRequest {
                RequestLock::refresh($request);
                if (self::settled($request)) {
                    throw AlreadyReached::here();
                }

                $request->forceFill([
                    'status' => $request->status->transitionTo(RequestStatus::Cancelled),
                    'cancelled_at' => CarbonImmutable::now()->startOfSecond(),
                    'cancel_reason' => $reason,
                    'open_stock_out_key' => null,
                    'version' => $request->version + 1,
                ])->save();

                return $request;
            }, $request, 'production_request.cancel');
        } catch (AlreadyReached) {
            return ProductionRequest::query()->findOrFail($request->id);
        }
    }

    /**
     * True when the request is already cancelled (it answers itself); false when it can be cancelled now.
     *
     * @throws ProductionError production.request.in_progress (409)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    private static function settled(ProductionRequest $request): bool
    {
        return match ($request->status) {
            RequestStatus::Cancelled => true,
            RequestStatus::Requested, RequestStatus::Accepted => false,
            RequestStatus::InProgress, RequestStatus::PendingConfirmation => throw ProductionError::inProgress($request->status->value),
            RequestStatus::Done => throw IllegalTransition::between($request->status, RequestStatus::Cancelled),
        };
    }
}
