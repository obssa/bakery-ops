<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Production\Enums\RequestPriority;
use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Enums\RequestStatus;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionRequest;
use App\Modules\Production\Support\RequestAccess;
use App\Shared\Actor\ActorContext;
use App\Shared\Tenancy\CurrentBranch;
use App\Shared\Values\Quantity;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The one way a production request is made (phase-2 plan sections 4 and 5): POST /production/requests, Requests::create and the
 * automatic request of CreateAutoRequest all come here. One AuditedAction (production_request.create, or
 * production_request.auto_create for the automatic one) writes a `requested` row and NO movement, and announces it
 * (ProductionRequested, after commit). Everything is checked before anything is written:
 *   - the product is a live to-stock product of this branch with an active recipe that has lines, and a station makes it;
 *   - the quantity is given in the product base unit, else it is the batch size (Items::batchQty);
 *   - the person (not for the automatic request, which runs as whoever ran the product out) may ask for production, and only
 *     a planner sets a due time or a priority other than normal;
 *   - a manual request for a product that already has an open request is refused, naming it; a stock-out request finds the
 *     open stock-out request of the product instead of making a second (the unique open key arbitrates, also between two
 *     workers, and the row is claimed by an INSERT, never by locking a row that may not exist); scheduled requests stack.
 * The client UUID makes an offline replay safe even when the Idempotency-Key was lost (ADR 0003): a client UUID already used
 * answers that request as it stands, writing and announcing nothing.
 */
final class CreateRequest
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly Items $items,
        private readonly Recipes $recipes,
        private readonly Routing $routing,
        private readonly RequestAccess $access,
        private readonly RequestAnnouncer $announcer,
        private readonly CurrentBranch $branch,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @return array{0: ProductionRequest, 1: bool} the request, and whether this call created it
     *
     * @throws ProductionError production.product.not_producible, no_station (422), plan.required (403), request.already_open (409)
     */
    public function handle(NewRequest $new, bool $automatic = false): array
    {
        $existing = ProductionRequest::query()->where('client_uuid', $new->clientUuid)->first();
        if ($existing instanceof ProductionRequest) {
            return [$existing, false];
        }

        $personId = $automatic
            ? ($this->actor->effectiveUserId() ?? $this->actor->requireUserId())
            : $this->access->person('production.request');
        if (! $automatic && ($new->due !== null || $new->priority !== RequestPriority::Normal)) {
            $this->access->assertMayPlan($personId);
        }

        $stationId = $this->producing($new->product);
        $qty = $new->qty === null ? $this->items->batchQty($new->product) : Quantity::of($new->qty);
        if ($qty === null || ! $qty->isPositive()) {
            throw ProductionError::notProducible($new->product);
        }
        $open = $this->openRequest($new);
        if ($open instanceof ProductionRequest) {
            return $new->reason === RequestReason::StockOut ? [$open, false] : throw ProductionError::alreadyOpen($open->id);
        }

        try {
            $request = $this->audit->run(function () use ($new, $personId, $stationId, $qty): ProductionRequest {
                $stamp = $this->actor->stamp();
                $request = new ProductionRequest;
                $request->forceFill([
                    'branch_id' => $this->branch->id(),
                    'client_uuid' => $new->clientUuid,
                    'product_item_id' => $new->product,
                    'requested_qty' => $qty,
                    'due_at' => $new->due,
                    'priority' => $new->priority,
                    'station_id' => $stationId,
                    'reason' => $new->reason,
                    'status' => RequestStatus::Requested,
                    'requested_by_user_id' => $personId,
                    'source_alert_id' => $new->sourceAlertId,
                    'open_stock_out_key' => $new->reason === RequestReason::StockOut ? 1 : null,
                    'note' => $new->note,
                    'created_by_user_id' => $stamp['created_by_user_id'],
                    'acting_user_id' => $stamp['acting_user_id'],
                    'shift_instance_id' => $stamp['shift_instance_id'],
                    'actor_station_id' => $stamp['station_id'],
                    'device_id' => $stamp['device_id'],
                    'version' => 1,
                ])->save();
                $this->announcer->requested($request);

                return $request;
            }, null, $automatic ? 'production_request.auto_create' : 'production_request.create');
        } catch (UniqueConstraintViolationException $e) {
            // the same request arrived twice at once (the client UUID) or a second stock-out request raced for the open key:
            // the other write won, and it announces itself
            $existing = ProductionRequest::query()->where('client_uuid', $new->clientUuid)->first()
                ?? ($new->reason === RequestReason::StockOut ? $this->openRequest($new) : null);
            if (! $existing instanceof ProductionRequest) {
                throw $e;
            }

            return [$existing, false];
        }

        return [$request, true];
    }

    /**
     * The id of the station that makes the product, when it may be made at all.
     *
     * @throws ProductionError production.product.not_producible (422), production.product.no_station (422)
     */
    private function producing(int $productId): int
    {
        $summary = $this->items->summaries([$productId])[$productId] ?? null;
        if ($summary === null || ! $summary->isToStock() || ! $summary->isActive) {
            throw ProductionError::notProducible($productId);
        }
        $recipe = $this->recipes->activeFor($productId);
        if ($recipe === null || $recipe->recipeId === null || $recipe->lines === []) {
            throw ProductionError::notProducible($productId);
        }
        $station = $this->routing->productionStationFor($productId);
        if ($station === null) {
            throw ProductionError::noStation($productId);
        }

        return $station->id;
    }

    /**
     * The request that already covers this one: the open stock-out request of the product for a stock-out request, any open
     * request of the product for a manual one, none for a scheduled one (scheduled requests may stack).
     */
    private function openRequest(NewRequest $new): ?ProductionRequest
    {
        $query = ProductionRequest::query()->where('product_item_id', $new->product);

        return match ($new->reason) {
            RequestReason::StockOut => $query->where('open_stock_out_key', 1)->first(),
            RequestReason::Manual => $query->whereIn('status', array_map(static fn (RequestStatus $s): string => $s->value, RequestStatus::open()))->orderBy('id')->first(),
            RequestReason::Schedule => null,
        };
    }
}
