<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Support\LineContext;
use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal Orders makes, as a stable message_key (lang/<locale>/orders.php, rule 9) plus the HTTP
 * status it maps to and what it is about (the line, the field, the order's status). Thrown inside an AuditedAction it
 * rolls the whole write back; Laravel renders it through render().
 */
final class OrderError extends RuntimeException
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

    /** Only an open order changes, is sent or is cancelled; a sent one is voided (step 1.3d). */
    public static function notOpen(OrderStatus $status): self
    {
        return new self('orders.order.not_open', 409, ['status' => $status->value]);
    }

    /**
     * The person acting by short code on a shared pad does not hold the permission the route asks of the logged-in
     * account (the same answer as the permission middleware's, shared lang key auth.forbidden).
     */
    public static function forbidden(): self
    {
        return new self('auth.forbidden', 403);
    }

    /** Another waiter's order: only its owner, or someone who works at every station (stations.all), changes it. */
    public static function notOwner(): self
    {
        return new self('orders.order.not_owner', 403);
    }

    public static function empty(): self
    {
        return new self('orders.order.empty', 422);
    }

    /** Not a live, active product of this branch (an ingredient, another branch's item, an inactive or unknown one). */
    public static function itemNotSellable(LineContext $line): self
    {
        return new self('orders.item.not_sellable', 422, $line->toArray());
    }

    public static function noPrice(LineContext $line): self
    {
        return new self('orders.item.no_price', 422, $line->toArray());
    }

    /** A to-order product without an active recipe: its ingredients cannot be held (rule 3). */
    public static function noRecipe(LineContext $line): self
    {
        return new self('orders.item.no_recipe', 422, $line->toArray());
    }

    /** No station makes it (a to-order product) or no stock location holds it (Inventory Locations::forProduct). */
    public static function noLocation(LineContext $line): self
    {
        return new self('orders.item.no_location', 422, $line->toArray());
    }

    public static function modifierNotOfItem(LineContext $line, int $modifierId): self
    {
        return new self('orders.modifier.not_of_item', 422, $line->toArray('modifier_ids') + ['modifier_id' => $modifierId]);
    }

    /** A line keeps its product and modifiers under its client UUID; anything else is a new line. */
    public static function lineChanged(LineContext $line, string $field): self
    {
        return new self('orders.line.changed', 422, $line->toArray($field));
    }

    public static function tableUnknown(): self
    {
        return new self('orders.table.unknown', 422, ['field' => 'dining_table_id']);
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
