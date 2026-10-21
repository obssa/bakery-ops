<?php

declare(strict_types=1);

namespace App\Modules\Production\Contracts;

use App\Modules\Production\Actions\CreateRequest;
use App\Modules\Production\Actions\NewRequest;
use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Values\Quantity;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * manifest production.provides: "Requests::create(product, qty, due, reason)" (phase-2 plan section 6 item 7). The way a
 * request for a to-stock product is made for code that is not an HTTP request; POST /production/requests and the StockOut
 * listener `CreateAutoRequest` use the same action (Actions\CreateRequest), so every rule is one rule.
 *   create: one audited action (production_request.create) as the person acting. The product is given by id (Production
 *     reaches Catalog by its contracts, never its models) and must be a live to-stock product with an active recipe and a
 *     producing station (Routing::productionStationFor); the quantity is in the product base unit and defaults, where the
 *     caller gives none, to Catalog Items::batchQty. A stock_out request holds the open_stock_out_key, so a second one for the
 *     same product answers the first; a manual one for a product with an open request is refused. A due time or a priority is
 *     not offered here: the endpoint takes them for planners. ProductionRequested is announced after commit.
 *
 * A final class resolved per use (it carries the request-scoped ActorContext and CurrentBranch, traps.md, Octane).
 */
final class Requests
{
    public function __construct(private readonly CreateRequest $create) {}

    /**
     * @param  Quantity|string|null  $qty  in the product base unit; null asks for the product batch size
     *
     * @throws ProductionError production.product.not_producible, production.product.no_station (422),
     *                         production.request.already_open (409), production.plan.required (403)
     */
    public function create(int $product, Quantity|string|null $qty, ?DateTimeInterface $due, RequestReason $reason): ProductionRequest
    {
        [$request] = $this->create->handle(new NewRequest(
            clientUuid: (string) Str::uuid(),
            product: $product,
            qty: $qty,
            due: $due,
            reason: $reason,
        ));

        return $request;
    }
}
