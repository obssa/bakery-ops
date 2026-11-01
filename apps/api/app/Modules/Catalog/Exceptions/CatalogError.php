<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Shared\DomainRefusal;
use App\Shared\Http\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal Catalog makes, as a stable message_key (lang/<locale>/catalog.php, rule 9) plus the HTTP
 * status it maps to. Thrown inside an AuditedAction it rolls the write back; Laravel renders it through render().
 */
final class CatalogError extends RuntimeException implements DomainRefusal
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

    /**
     * Step 4.1b: a refunded unit goes back into stock only where its sale took the product itself out of stock (sold from stock or bought
     * in); a product made to order uses its recipe's ingredients and an ingredient is never sold.
     */
    public static function restockNotStocked(): self
    {
        return new self('catalog.item.restock_not_stocked', 422, ['field' => 'refund_restock']);
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

    /** A publish needs a version 1 to change; PUT /items/{item}/recipe makes it. */
    public static function recipeNoBase(): self
    {
        return new self('catalog.recipe.no_base', 409);
    }

    /** The recipe sent is the active version as it stands (the change note does not count). */
    public static function recipeUnchanged(): self
    {
        return new self('catalog.recipe.unchanged', 409);
    }

    /** PUT /items/{item}/recipe replaces version 1 only while it is the only version; later changes are published. */
    public static function recipePublished(): self
    {
        return new self('catalog.recipe.published', 409);
    }

    /** A second publish got past the lock (the unique key refused it): nothing was written, try again. */
    public static function recipePublishConflict(): self
    {
        return new self('catalog.recipe.publish_conflict', 409);
    }

    /** DomainRefusal (step 5.5a): the key the info line carries instead of a stack trace. */
    public function messageKey(): string
    {
        return $this->messageKey;
    }

    /** DomainRefusal (step 5.5a): a 4xx is a refusal and is not reported; a 5xx is a failure and is. */
    public function httpStatus(): int
    {
        return $this->status;
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
