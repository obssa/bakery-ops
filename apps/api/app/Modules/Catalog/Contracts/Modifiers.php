<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Modifier;
use App\Shared\I18n\Bilingual;

/**
 * manifest catalog.provides: "Modifiers::for(item) with price and recipe deltas". The live modifiers of a product in
 * creation order, the price delta as Money and every recipe delta converted to the ingredient's base unit.
 */
final class Modifiers
{
    public function __construct(private readonly Units $units) {}

    /**
     * @return list<ModifierView>
     */
    public function for(Item|int $item): array
    {
        $itemId = $item instanceof Item ? $item->id : $item;

        return array_values(Modifier::query()
            ->where('item_id', $itemId)
            ->orderBy('id')
            ->get()
            ->map(fn (Modifier $m): ModifierView => new ModifierView(
                $m->id,
                $m->item_id,
                new Bilingual($m->name_en, $m->name_am),
                $m->price_delta,
                $this->delta($m),
            ))
            ->all());
    }

    /**
     * @return list<RecipeDeltaLine>
     */
    public function delta(Modifier $modifier): array
    {
        $lines = [];
        foreach ($modifier->recipe_delta ?? [] as $line) {
            $lines[] = new RecipeDeltaLine(
                (int) $line['ingredient_item_id'],
                $this->units->toBase((string) $line['qty'], (int) $line['unit_id']),
                $this->units->baseOf((int) $line['unit_id'])->code,
            );
        }

        return $lines;
    }

    /**
     * What a menu offers for many products at once, in one query: the live modifiers of each in creation order, as
     * Modifiers::for lists them, without recipe deltas. A product without modifiers has an empty list.
     *
     * @param  list<int>  $itemIds
     * @return array<int, list<ModifierOffer>> item id => offers
     */
    public function offeredFor(array $itemIds): array
    {
        $ids = array_values(array_unique($itemIds));
        $offers = array_fill_keys($ids, []);
        if ($ids === []) {
            return $offers;
        }
        foreach (Modifier::query()->whereIn('item_id', $ids)->orderBy('id')->get() as $m) {
            $offers[$m->item_id][] = new ModifierOffer($m->id, $m->item_id, new Bilingual($m->name_en, $m->name_am), $m->price_delta);
        }

        return $offers;
    }
}
