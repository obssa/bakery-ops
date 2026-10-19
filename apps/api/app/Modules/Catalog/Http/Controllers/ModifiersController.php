<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Audit\Contracts\AuditedAction;
use App\Modules\Catalog\Contracts\Units;
use App\Modules\Catalog\Exceptions\CatalogError;
use App\Modules\Catalog\Http\Requests\StoreModifierRequest;
use App\Modules\Catalog\Http\Resources\ModifierResource;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Modifier;
use App\Shared\Values\Money;
use App\Shared\Values\Quantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /items/{item}/modifiers, POST: a product's modifiers. Each recipe delta must be in a unit that converts to the
 * ingredient's base unit; quantities are stored as fixed-scale decimal strings (ADR 0004).
 */
final class ModifiersController
{
    public function __construct(
        private readonly AuditedAction $audit,
        private readonly Units $units,
    ) {}

    public function index(Item $item): AnonymousResourceCollection
    {
        return ModifierResource::collection(Modifier::query()->where('item_id', $item->id)->orderBy('id')->get());
    }

    public function store(StoreModifierRequest $request, Item $item): JsonResponse
    {
        if (! $item->isProduct()) {
            throw CatalogError::notAProduct();
        }
        $delta = $request->recipeDelta();
        $ingredientUnits = Item::query()->whereIn('id', array_column($delta, 'ingredient_item_id'))->pluck('unit_id', 'id');
        $stored = [];
        foreach ($delta as $i => $line) {
            $ingredientUnit = $ingredientUnits->get($line['ingredient_item_id']);
            if (! is_int($ingredientUnit) || ! $this->units->convertible($line['unit_id'], $ingredientUnit)) {
                throw CatalogError::unitIncompatible("recipe_delta.{$i}.unit_id");
            }
            $stored[] = [
                'ingredient_item_id' => $line['ingredient_item_id'],
                'qty' => Quantity::of($line['qty'])->toString(),
                'unit_id' => $line['unit_id'],
            ];
        }

        $modifier = $this->audit->run(function () use ($request, $item, $stored): Modifier {
            $modifier = new Modifier;
            $modifier->forceFill([
                'branch_id' => $item->branch_id,
                'item_id' => $item->id,
                'name_en' => $request->nameEn(),
                'name_am' => $request->nameAm(),
                'price_delta' => Money::of($request->priceDelta()),
                'recipe_delta' => $stored === [] ? null : $stored,
            ])->save();

            return $modifier;
        }, null, 'modifier.create');

        return response()->json((new ModifierResource($modifier->fresh() ?? $modifier))->resolve($request), 201);
    }
}
