<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Models\Modifier;
use App\Shared\Values\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A modifier with its price delta and recipe delta as entered and in base units, all decimal strings (ADR 0004).
 *
 * @mixin Modifier
 */
final class ModifierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Modifier $modifier */
        $modifier = $this->resource;
        $units = app(Units::class);

        $delta = [];
        foreach ($modifier->recipe_delta ?? [] as $line) {
            $unit = $units->resolve((int) $line['unit_id']);
            $delta[] = [
                'ingredient_item_id' => (int) $line['ingredient_item_id'],
                'qty' => Quantity::of((string) $line['qty'])->toString(),
                'unit_id' => $unit->id,
                'unit_code' => $unit->code,
                'qty_base' => $units->toBase((string) $line['qty'], $unit)->toString(),
                'base_unit_code' => $units->baseOf($unit)->code,
            ];
        }

        return [
            'id' => $modifier->id,
            'item_id' => $modifier->item_id,
            'name_en' => $modifier->name_en,
            'name_am' => $modifier->name_am,
            'price_delta' => $modifier->price_delta->amount(),
            'recipe_delta' => $delta,
        ];
    }
}
