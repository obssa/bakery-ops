<?php

declare(strict_types=1);

namespace App\Modules\Production\Contracts;

use App\Modules\Production\Actions\ConfirmBatch;
use App\Modules\Production\Actions\FinishBatch;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionBatch;
use App\Shared\StateMachine\IllegalTransition;
use App\Shared\Values\Quantity;
use InvalidArgumentException;

/**
 * manifest production.provides, as the plan splits it (phase-2 plan section 6 item 7, step 2.7c): `Batches::finish(batch, produced,
 * waste)` records what was made and, where the station needs no overseer, confirms in the same write; `Batches::confirm(batch,
 * confirmer)` is the overseer's sign-off. POST /production/batches/{batch}/done and /confirm are thin callers of the same actions
 * (Actions\FinishBatch, Actions\ConfirmBatch), so every rule is one rule:
 *   finish: the person acting (ActorContext) must hold production.fulfil at the batch's station and be the request's assignee. The batch
 *     goes started -> finished and the request to pending_confirmation, nothing in the ledger; where the station does not require an
 *     overseer it goes on to confirmed in the same write (confirmed_at = finished_at), the request to done, and the stock is written.
 *   confirm: $confirmer must hold production.confirm or be the overseer flagged at the station, and must not be the person who finished
 *     the batch. The stock is written, the batch is confirmed and the request is done. Confirming a confirmed batch writes nothing.
 * The stock is written in one transaction by one Ledger::appendMany: N production_consume rows (the recipe version recorded at start,
 * scaled to produced plus waste, at the consume place) and one production_output row (produced, at the output place), all or nothing,
 * with client_uuids derived from the batch. A short ingredient refuses the whole write (409 production.batch.ingredient_short).
 * Batches and people are given by model or id, never an Identity or Catalog model (Production reaches those modules through their
 * contracts only). A final class resolved per use.
 */
final class Batches
{
    public function __construct(
        private readonly FinishBatch $finishBatch,
        private readonly ConfirmBatch $confirmBatch,
    ) {}

    /**
     * @param  Quantity|string  $produced  in the product base unit, never negative
     * @param  Quantity|string  $waste  in the product base unit, never negative; produced plus waste is above zero
     *
     * @throws InvalidArgumentException a negative quantity, or produced plus waste not above zero
     * @throws ProductionError auth.forbidden, production.station.forbidden (403), production.request.taken, production.batch.ingredient_short (409)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    public function finish(ProductionBatch|int $batch, Quantity|string $produced, Quantity|string $waste): ProductionBatch
    {
        $produced = Quantity::of($produced);
        $waste = Quantity::of($waste);
        if ($produced->isNegative() || $waste->isNegative() || ! $produced->plus($waste)->isPositive()) {
            throw new InvalidArgumentException('A batch makes or wastes something: produced and waste are never negative and their sum is above zero.');
        }

        return $this->finishBatch->handle($this->batch($batch), $produced, $waste);
    }

    /**
     * @param  int  $confirmer  the user id of the person who signs off; never the one who finished the batch
     *
     * @throws ProductionError production.confirm.forbidden (403), production.batch.self_confirm, production.batch.ingredient_short (409)
     * @throws IllegalTransition state_machine.illegal_transition (409)
     */
    public function confirm(ProductionBatch|int $batch, int $confirmer): ProductionBatch
    {
        return $this->confirmBatch->handle($this->batch($batch), $confirmer);
    }

    private function batch(ProductionBatch|int $batch): ProductionBatch
    {
        return $batch instanceof ProductionBatch ? $batch : ProductionBatch::query()->findOrFail($batch);
    }
}
