<?php

declare(strict_types=1);

namespace App\Modules\Production\Contracts;

use App\Modules\Production\Enums\RequestReason;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Values\Quantity;
use DateTimeInterface;
use LogicException;

/**
 * manifest production.provides: "Requests::create(product, qty, due, reason)" (phase-2 plan section 6 item 7). The way a
 * request for a to-stock product is made, for the endpoint of step 2.7b and for the StockOut listener `CreateAutoRequest`,
 * which use the same action.
 *   create: one audited action. The product is given by id (Production reaches Catalog by its contracts, never its models);
 *     the quantity is in the product base unit and defaults, where the caller gives none, to Catalog Items::batchQty; the
 *     producing station is Routing::productionStationFor. A stock_out request holds the open_stock_out_key, so a second one
 *     for the same product answers the first.
 *
 * SKELETON (step 2.7a): the signature is fixed here so Notifications, the web and the tests of 2.7b can be written against it;
 * the body arrives in step 2.7b and until then every call is a LogicException. A final class resolved per use (it will carry
 * the request-scoped ActorContext and CurrentBranch, traps.md, Octane).
 */
final class Requests
{
    /**
     * @param  Quantity|string|null  $qty  in the product base unit; null asks for the product batch size
     */
    public function create(int $product, Quantity|string|null $qty, ?DateTimeInterface $due, RequestReason $reason): ProductionRequest
    {
        throw new LogicException('Requests::create arrives in step 2.7b.');
    }
}
