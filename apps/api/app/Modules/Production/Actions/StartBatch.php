<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Support\RequestAccess;
use App\Shared\Actor\ActorContext;
use App\Shared\Models\Station;
use App\Shared\StateMachine\IllegalTransition;
use App\Shared\Tenancy\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * POST /production/requests/{request}/start (phase-2 plan section 4): the baker who accepted a request presses Start. A batch is
 * made (one per request) holding the recipe version the baker sees (consumption always uses it), the planned quantity and the two
 * places the batch will use: Locations::forStation of the producing station for the ingredients and Locations::forProduct for
 * the output, both fixed now so a later change of configuration does not move an open batch (422 production.locations.unresolved
 * when either is missing). The request becomes in_progress. Nothing is written to the ledger.
 *
 * Who: production.fulfil at the request's station (403 production.station.forbidden) and the request's assignee (409
 * production.request.taken for anyone else, the admin included). Which request: `accepted` only (409 production.request.not_accepted
 * for a `requested` one, 409 state_machine.illegal_transition naming any other status). The batch has a client UUID: starting a
 * request that already has a batch with that UUID is a repeat and answers the request as it stands, writing nothing. One
 * AuditedAction (production_batch.start) under the request row lock, so two taps make one batch.
 */
final class StartBatch
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly RequestAccess $access,
        private readonly Locations $locations,
        private readonly Recipes $recipes,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @throws ProductionError auth.forbidden, production.station.forbidden (403), production.request.not_accepted, production.request.taken
     *                         (409), production.locations.unresolved, production.product.not_producible (422)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    public function handle(ProductionRequest $request, string $clientUuid): ProductionRequest
    {
        $person = $this->access->person('production.fulfil');
        $this->access->assertAtStation($person, $request);
        if ($this->settled($request, $person, $clientUuid)) {
            return $request;
        }

        $station = Station::query()->withTrashed()->findOrFail($request->station_id);
        $consume = $this->locations->forStation($station);
        $output = $this->locations->forProduct($request->product_item_id);
        if ($consume === null || $output === null) {
            throw ProductionError::locationsUnresolved($request->station_id, $request->product_item_id);
        }
        $recipe = $this->recipes->activeFor($request->product_item_id);
        if ($recipe === null || $recipe->recipeId === null || $recipe->version === null || $recipe->lines === []) {
            throw ProductionError::notProducible($request->product_item_id);
        }

        try {
            $this->audit->run(function () use ($request, $person, $clientUuid, $consume, $output, $recipe): ProductionBatch {
                RequestLock::refresh($request);
                if ($this->settled($request, $person, $clientUuid)) {
                    throw AlreadyReached::here();
                }

                $stamp = $this->actor->stamp();
                $now = CarbonImmutable::now()->startOfSecond();
                $batch = new ProductionBatch;
                $batch->forceFill([
                    'branch_id' => $this->branch->id(),
                    'client_uuid' => $clientUuid,
                    'request_id' => $request->id,
                    'station_id' => $request->station_id,
                    'status' => BatchStatus::Started,
                    'recipe_id' => $recipe->recipeId,
                    'recipe_version' => $recipe->version,
                    'planned_qty' => $request->requested_qty,
                    'consume_location_id' => $consume->id,
                    'output_location_id' => $output->id,
                    'started_at' => $now,
                    'started_by_user_id' => $person,
                    'created_by_user_id' => $stamp['created_by_user_id'],
                    'acting_user_id' => $stamp['acting_user_id'],
                    'shift_instance_id' => $stamp['shift_instance_id'],
                    'actor_station_id' => $stamp['station_id'],
                    'device_id' => $stamp['device_id'],
                    'version' => 1,
                ])->save();

                $request->forceFill([
                    'status' => $request->status->transitionTo(RequestStatus::InProgress),
                    'version' => $request->version + 1,
                ])->save();

                return $batch;
            }, null, 'production_batch.start');
        } catch (AlreadyReached|UniqueConstraintViolationException $e) {
            // two taps at once: the other write made this very batch. A batch uuid that belongs to somewhere else is no repeat.
            if (! ProductionBatch::query()->where('request_id', $request->id)->where('client_uuid', $clientUuid)->exists()) {
                throw $e;
            }
        }

        return ProductionRequest::query()->findOrFail($request->id);
    }

    /**
     * True when the request already has this batch (it answers itself); false when it may be started now.
     *
     * @throws ProductionError production.request.not_accepted, production.request.taken (409)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    private function settled(ProductionRequest $request, int $person, string $clientUuid): bool
    {
        if (ProductionBatch::query()->where('request_id', $request->id)->where('client_uuid', $clientUuid)->exists()) {
            return true;
        }

        return match ($request->status) {
            RequestStatus::Requested => throw ProductionError::notAccepted($request->status->value),
            RequestStatus::Accepted => $request->assigned_to_user_id !== $person ? throw ProductionError::taken() : false,
            default => throw IllegalTransition::between($request->status, RequestStatus::InProgress),
        };
    }
}
