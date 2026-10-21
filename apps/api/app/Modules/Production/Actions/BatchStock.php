<?php

declare(strict_types=1);

namespace App\Modules\Production\Actions;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\ScaledLine;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Inventory\Ledger\Ledger;
use App\Modules\Inventory\Ledger\MovementIntent;
use App\Modules\Inventory\Ledger\MovementReason;
use App\Modules\Production\Exceptions\ProductionError;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Support\BatchMovementUuid;
use App\Shared\Values\Quantity;

/**
 * The one place a batch writes stock (phase-2 plan section 4, "How a batch consumes and produces"; section 10.4). Called inside the
 * caller's transaction, after the request and batch rows are locked:
 *   - the recipe version the batch recorded at start is scaled by Catalog (Recipes::scaled) to produced PLUS waste, because the
 *     ingredients were spent on the spoiled units too; every non-optional line becomes one production_consume movement at the
 *     consume place, waste applied once inside the scaling, lines that scale to zero left out;
 *   - the produced units are one production_output movement at the output place (none when everything made was wasted: waste units
 *     were never in stock);
 *   - the balance rows of every (item, place) the write touches are locked in ONE ascending pass (Ledger::lock: the ledger's own
 *     order), and each ingredient's `available` (on hand minus reserved) is compared with what the batch takes, so production
 *     cannot take stock that open orders hold: any short ingredient is 409 production.batch.ingredient_short naming every one,
 *     and the caller's transaction rolls back (negative stock stays refused, section 10.4);
 *   - then ONE Ledger::appendMany writes all the rows, all or nothing, with client_uuids derived from the batch
 *     (BatchMovementUuid), so a replay or a second confirm cannot write twice.
 * Returns the id of the output movement, or null when none was written.
 */
final class BatchStock
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Recipes $recipes,
        private readonly Items $items,
        private readonly Locations $locations,
    ) {}

    /**
     * @throws ProductionError production.batch.ingredient_short (409), production.product.not_producible (422) when the recorded
     *                         recipe version no longer exists
     */
    public function write(ProductionBatch $batch, int $productId, Quantity $produced, Quantity $waste): ?int
    {
        $scaled = $this->recipes->scaled($productId, $produced->plus($waste), $batch->recipe_version);
        if ($scaled === null) {
            throw ProductionError::notProducible($productId);
        }

        $intents = [];
        $needed = [];
        foreach ($scaled->lines as $line => $consumption) {
            if ($consumption->qty->isZero()) {
                continue;
            }
            $intents[] = MovementIntent::of(
                $consumption->ingredientItemId,
                $batch->consume_location_id,
                $consumption->qty->negated(),
                MovementReason::ProductionConsume,
                'production_batch',
                $batch->id,
                null,
                BatchMovementUuid::consume($batch->branch_id, $batch->client_uuid, $line),
            );
            $needed[$consumption->ingredientItemId] = ($needed[$consumption->ingredientItemId] ?? Quantity::zero())->plus($consumption->qty);
        }
        $makesOutput = $produced->isPositive();
        if ($makesOutput) {
            $intents[] = MovementIntent::of(
                $productId,
                $batch->output_location_id,
                $produced,
                MovementReason::ProductionOutput,
                'production_batch',
                $batch->id,
                null,
                BatchMovementUuid::output($batch->branch_id, $batch->client_uuid),
            );
        }
        if ($intents === []) {
            return null;
        }

        $pairs = [];
        foreach ($intents as $intent) {
            $pairs[] = [$intent->itemId, $intent->locationId];
        }
        $levels = $this->ledger->lock($pairs);
        $this->assertAvailable($batch, $needed, $levels, $scaled->lines);

        $rows = $this->ledger->appendMany($intents);

        $output = $makesOutput ? end($rows) : false;

        return $output === false ? null : $output->id;
    }

    /**
     * @param  array<int, Quantity>  $needed  ingredient id => what the batch takes in all
     * @param  array<string, StockLevel>  $levels  by Ledger::key()
     * @param  list<ScaledLine>  $lines
     *
     * @throws ProductionError production.batch.ingredient_short (409)
     */
    private function assertAvailable(ProductionBatch $batch, array $needed, array $levels, array $lines): void
    {
        $short = [];
        foreach ($needed as $ingredientId => $need) {
            $available = ($levels[Ledger::key($ingredientId, $batch->consume_location_id)] ?? null)?->available() ?? Quantity::zero();
            if ($available->isLessThan($need)) {
                $short[$ingredientId] = [$need, $available];
            }
        }
        if ($short === []) {
            return;
        }

        $units = [];
        foreach ($lines as $line) {
            $units[$line->ingredientItemId] = $line->unit;
        }
        ksort($short);
        $names = $this->items->summaries(array_keys($short));
        $place = $this->locations->find($batch->consume_location_id);
        $shortfall = [];
        foreach ($short as $ingredientId => [$need, $available]) {
            $shortfall[] = [
                'ingredient' => [
                    'id' => $ingredientId,
                    'name_en' => $names[$ingredientId]->nameEn ?? '',
                    'name_am' => $names[$ingredientId]->nameAm ?? '',
                    'unit' => $units[$ingredientId] ?? ($names[$ingredientId]->unit ?? ''),
                ],
                'needed' => $need->toString(),
                'available' => $available->toString(),
                'short' => $need->minus($available)->toString(),
                'location_id' => $batch->consume_location_id,
                'location' => $place === null ? null : ['id' => $place->id, 'name_en' => $place->name_en, 'name_am' => $place->name_am],
            ];
        }

        throw ProductionError::ingredientShort($shortfall);
    }
}
