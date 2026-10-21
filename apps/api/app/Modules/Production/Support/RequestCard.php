<?php

declare(strict_types=1);

namespace App\Modules\Production\Support;

use App\Modules\Catalog\Contracts\Items;
use App\Modules\Catalog\Contracts\Recipes;
use App\Modules\Catalog\Contracts\ScaledLine;
use App\Modules\Catalog\Contracts\ScaledRecipe;
use App\Modules\Inventory\Contracts\Locations;
use App\Modules\Inventory\Contracts\Stock;
use App\Modules\Inventory\Contracts\StockLevel;
use App\Modules\Production\Enums\BatchStatus;
use App\Modules\Production\Models\ProductionBatch;
use App\Modules\Production\Models\ProductionRequest;
use App\Shared\Models\Station;
use App\Shared\Values\Quantity;

/**
 * What the baker works from (phase-2 plan section 4, "The card"): the active recipe scaled to the requested quantity by Catalog
 * (Recipes::scaled: every non-optional line in base units with waste applied once, the steps in order in both languages), and
 * for each ingredient what is at the place the station works from (on hand, and available = on hand minus reserved, Inventory's
 * Stock), whether it is short and by how much, and, for a short one, what the store could give (the hint for a transfer request).
 * The two places a batch will use are named: consume_location (Locations::forStation of the producing station) and
 * output_location (Locations::forProduct: where the pad reads the stock, so "back on the pad" needs no transfer).
 *
 * Two answers are deliberately null rather than a guess: recipe is null when the product no longer has an active recipe, and,
 * when the station has no stock place, consume_location is null and every ingredient says needed only (on_hand, available,
 * short, short_qty and transfer_hint are null: nothing can be said about stock that has no place). The card of a request that
 * has a batch (step 2.7c) is made from the version the batch recorded at start and the places it fixed, scaled to its planned
 * quantity while it is started and to produced plus waste once it is finished or confirmed (what Confirm takes). Each ingredient is read once: a fixed number of queries however long the
 * recipe. Resolved per use.
 */
final class RequestCard
{
    public function __construct(
        private readonly Recipes $recipes,
        private readonly Items $items,
        private readonly Locations $locations,
        private readonly Stock $stock,
    ) {}

    /**
     * @return array{recipe: array<string, mixed>|null, consume_location: array<string, mixed>|null, output_location: array<string, mixed>|null}
     */
    public function build(ProductionRequest $request): array
    {
        $batch = $request->batch;
        if ($batch instanceof ProductionBatch) {
            // a batch fixed its places and its recipe version at Start: the card shows those, whatever was configured or published since,
            // and for a finished one the quantity Confirm will take: produced plus waste
            $consume = $this->locations->find($batch->consume_location_id);
            $output = $this->locations->find($batch->output_location_id);
            $target = $batch->status === BatchStatus::Started || $batch->produced_qty === null || $batch->waste_qty === null
                ? $batch->planned_qty
                : $batch->produced_qty->plus($batch->waste_qty);
            $scaled = $this->recipes->scaled($request->product_item_id, $target, $batch->recipe_version);
        } else {
            $station = Station::query()->withTrashed()->find($request->station_id);
            $consume = $station instanceof Station ? $this->locations->forStation($station) : null;
            $output = $this->locations->forProduct($request->product_item_id);
            $scaled = $this->recipes->scaled($request->product_item_id, $request->requested_qty);
        }

        return [
            'recipe' => $scaled === null ? null : $this->recipe($scaled, $consume?->id),
            'consume_location' => $consume === null ? null : ['id' => $consume->id, 'name_en' => $consume->name_en, 'name_am' => $consume->name_am],
            'output_location' => $output === null ? null : ['id' => $output->id, 'name_en' => $output->name_en, 'name_am' => $output->name_am],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recipe(ScaledRecipe $scaled, ?int $consumeLocationId): array
    {
        $ingredients = $this->items->summaries(array_map(static fn (ScaledLine $line): int => $line->ingredientItemId, $scaled->lines));
        $here = $consumeLocationId === null ? null : $this->stock->at($consumeLocationId);
        $store = $this->locations->store();
        $atStore = $store === null ? null : $this->stock->at($store->id);

        $lines = [];
        foreach ($scaled->lines as $line) {
            $ingredient = $ingredients[$line->ingredientItemId] ?? null;
            $level = $here === null ? null : ($here[$line->ingredientItemId] ?? StockLevel::empty($line->ingredientItemId, (int) $consumeLocationId));
            $short = $level !== null && $level->available()->isLessThan($line->qty);

            $lines[] = [
                'ingredient' => [
                    'id' => $line->ingredientItemId,
                    'name_en' => $ingredient->nameEn ?? '',
                    'name_am' => $ingredient->nameAm ?? '',
                    'unit' => $line->unit,
                ],
                'needed' => $line->qty->toString(),
                'on_hand' => $level?->onHand->toString(),
                'available' => $level?->available()->toString(),
                'short' => $level === null ? null : $short,
                'short_qty' => $level === null ? null : ($short ? $line->qty->minus($level->available())->toString() : Quantity::zero()->toString()),
                'transfer_hint' => $short && $store !== null && $atStore !== null
                    ? ['from_location_id' => $store->id, 'store_available' => ($atStore[$line->ingredientItemId] ?? StockLevel::empty($line->ingredientItemId, $store->id))->available()->toString()]
                    : null,
            ];
        }

        return [
            'version' => $scaled->version,
            'yield' => ['qty' => $scaled->yield->toString(), 'unit' => $scaled->yieldUnit],
            'target_qty' => $scaled->target->toString(),
            'lines' => $lines,
            'steps' => array_map(static fn ($step): array => $step->toArray(), $scaled->steps),
        ];
    }
}
