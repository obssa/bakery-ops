<?php

declare(strict_types=1);

namespace App\Modules\Production\Listeners;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\Routing;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Production\Actions\CreateRequest;
use App\Modules\Production\Actions\NewRequest;
use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Support\AutoRequestUuid;
use App\Modules\Production\Support\EventActor;
use App\Shared\Events\StockOut;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * StockOut -> one production request for the batch size at the station that makes the product (phase-2 plan section 5, ADR 0011).
 * It acts only when a product that is kept in stock (`to_stock`) with an active recipe that has lines and a producing station
 * ran out in the place it is sold from (Locations::forProduct: the display case, or the kitchen for a toast slice); otherwise it
 * ends quietly: a stock-out of an ingredient, a bought-in or made-to-order product, a product nobody makes or has no recipe for,
 * or of a product in a place it is only kept on the way (the cake room) is not production's to fix; the admin is told by the
 * notification alone. A stock-out nobody is known to have caused (no person to run as, rule 4) ends quietly too.
 *
 * The request is made by CreateRequest as the automatic one: reason `stock_out`, client_uuid the UUIDv5 of the alert
 * (AutoRequestUuid), source_alert_id the episode, one AuditedAction (production_request.auto_create), ProductionRequested after
 * commit. The unique open stock-out key makes a retried or racing job find the existing request and stop, and a run-out while an
 * earlier request of the product is still open finds that one; once it is done or cancelled the next run-out makes a new one.
 *
 * Queued after commit on the default queue (the live host drains the database queue once a minute): the request exists one
 * queue hop after the movement that ran the product out, and its notification one hop after that. Runs as the person whose
 * movement caused the stock-out, in the event's branch (Support\EventActor).
 */
final class CreateAutoRequest implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public int $tries = 3;

    public function __construct(
        private readonly EventActor $scope,
        private readonly Items $items,
        private readonly Recipes $recipes,
        private readonly Routing $routing,
        private readonly Locations $locations,
        private readonly CreateRequest $create,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(StockOut $event): void
    {
        if ($event->itemKind !== 'product' || $event->fulfilment !== 'to_stock' || $event->causedByUserId === null) {
            return;
        }

        $this->scope->run($event->branchId, $event->causedByUserId, function () use ($event): void {
            if (! $this->wanted($event)) {
                return;
            }

            try {
                $this->create->handle(new NewRequest(
                    clientUuid: AutoRequestUuid::forAlert($event->branchId, $event->alertId),
                    product: $event->itemId,
                    qty: null,
                    due: null,
                    reason: RequestReason::StockOut,
                    sourceAlertId: $event->alertId,
                ), true);
            } catch (ProductionError) {
                // the product stopped being producible between the stock-out and now: nothing to ask for
            }
        });
    }

    /** Is this a product production makes, that ran out where it is sold? */
    private function wanted(StockOut $event): bool
    {
        $summary = $this->items->summaries([$event->itemId])[$event->itemId] ?? null;
        if ($summary === null || ! $summary->isToStock() || ! $summary->isActive) {
            return false;
        }
        $place = $this->locations->forProduct($event->itemId);
        if ($place === null || $place->id !== $event->locationId) {
            return false;
        }
        $recipe = $this->recipes->activeFor($event->itemId);
        if ($recipe === null || $recipe->recipeId === null || $recipe->lines === []) {
            return false;
        }

        return $this->routing->productionStationFor($event->itemId) !== null && $this->items->batchQty($event->itemId) !== null;
    }
}
