<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Shared\Http\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal Catalog makes, as a stable message_key (lang/<locale>/catalog.php, rule 9) plus the HTTP
 * status it maps to. Thrown inside an AuditedAction it rolls the write back; Laravel renders it through render().
 */
final class CatalogError extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        public readonly string $messageKey,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($messageKey);
    }

    /** Recipes, station routing and modifiers belong to products; an ingredient has none. */
    public static function notAProduct(): self
    {
        return new self('catalog.item.not_a_product', 422);
    }

    /** An ingredient an active recipe still uses cannot be deleted. */
    public static function itemInUse(): self
    {
        return new self('catalog.item.in_use', 409);
    }

    public static function categoryInUse(): self
    {
        return new self('catalog.category.in_use', 409);
    }

    public static function categoryCycle(): self
    {
        return new self('catalog.category.cycle', 422);
    }

    /** A quantity in a unit that does not convert to the base unit the item is stocked in ($field names the input). */
    public static function unitIncompatible(string $field): self
    {
        return new self('catalog.unit.incompatible', 422, ['field' => $field]);
    }

    /** Price history only moves forward: a new price starts after the open one. */
    public static function priceTooEarly(CarbonImmutable $openSince): self
    {
        return new self('catalog.price.too_early', 422, ['current_effective_from' => $openSince->toIso8601String()]);
    }

    public static function recipeBoughtIn(): self
    {
        return new self('catalog.recipe.bought_in', 422);
    }

    public static function recipeNotFound(): self
    {
        return new self('catalog.recipe.not_found', 404);
    }

    public static function recipeSelfReference(): self
    {
        return new self('catalog.recipe.self_reference', 422);
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
