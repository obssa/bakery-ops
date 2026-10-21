<?php

declare(strict_types=1);

namespace App\Modules\Production\Contracts;

use App\Modules\Production\Models\ProductionBatch;
use App\Shared\Values\Quantity;
use LogicException;

/**
 * manifest production.provides, as the plan splits it (phase-2 plan section 6 item 7, step 2.7c): `Batches::finish(batch,
 * produced, waste)` records what was made and, where the station needs no overseer, confirms in the same write;
 * `Batches::confirm(batch, confirmer)` is the overseer's sign-off and the one place the stock is written (N production_consume
 * rows and one production_output row in one Ledger::appendMany, with client_uuids derived from the batch).
 *
 * SKELETON (step 2.7a): the signatures are fixed here; the bodies arrive in step 2.7c and until then every call is a
 * LogicException. Batches and people are given by model or id, never an Identity or Catalog model (Production reaches those
 * modules through their contracts only). A final class resolved per use.
 */
final class Batches
{
    /**
     * @param  Quantity|string  $produced  in the product base unit, never negative
     * @param  Quantity|string  $waste  in the product base unit, never negative; produced plus waste is above zero
     */
    public function finish(ProductionBatch|int $batch, Quantity|string $produced, Quantity|string $waste): ProductionBatch
    {
        throw new LogicException('Batches::finish arrives in step 2.7c.');
    }

    /**
     * @param  int  $confirmer  the user id of the person who signs off; never the one who finished the batch
     */
    public function confirm(ProductionBatch|int $batch, int $confirmer): ProductionBatch
    {
        throw new LogicException('Batches::confirm arrives in step 2.7c.');
    }
}
