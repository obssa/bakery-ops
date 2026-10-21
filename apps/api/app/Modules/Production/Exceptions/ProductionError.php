<?php

declare(strict_types=1);

namespace App\Modules\Production\Exceptions;

use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal the production request actions make, as a stable message_key (lang/<locale>/production.php,
 * rule 9) plus the HTTP status it maps to and what it is about (the request, the status, the product). Thrown inside an
 * AuditedAction it rolls the whole write back; Laravel renders it through render().
 */
final class ProductionError extends RuntimeException
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

    /** The person acting by short code does not hold the permission the logged-in account passed the route with, or does not exist. */
    public static function forbidden(): self
    {
        return new self('auth.forbidden', 403);
    }

    /** A due time or a priority other than normal is the planner's (production.plan). */
    public static function planRequired(): self
    {
        return new self('production.plan.required', 403);
    }

    /** The baker does not work at the station that makes the request, and does not work at every station. */
    public static function stationForbidden(): self
    {
        return new self('production.station.forbidden', 403);
    }

    /** Only a planner, or the person who asked, cancels a request. */
    public static function cancelForbidden(): self
    {
        return new self('production.cancel.forbidden', 403);
    }

    /** A manual request for a product that already has an open one: the open request is named. */
    public static function alreadyOpen(int $requestId): self
    {
        return new self('production.request.already_open', 409, ['request_id' => $requestId]);
    }

    /** The request is assigned to, or accepted by, somebody else. */
    public static function taken(): self
    {
        return new self('production.request.taken', 409);
    }

    /** A request whose batch is started or finished is finished or sent back, never cancelled. */
    public static function inProgress(string $status): self
    {
        return new self('production.request.in_progress', 409, ['status' => $status]);
    }

    /** Not a product that is kept in stock and made from an active recipe of this branch. */
    public static function notProducible(int $productId): self
    {
        return new self('production.product.not_producible', 422, ['product_item_id' => $productId]);
    }

    /** Nobody makes this product: it has no producing station. */
    public static function noStation(int $productId): self
    {
        return new self('production.product.no_station', 422, ['product_item_id' => $productId]);
    }

    /** The person named does not hold production.fulfil at the request's station (or is not an active person of this branch). */
    public static function assigneeInvalid(int $userId): self
    {
        return new self('production.assignee.invalid', 422, ['user_id' => $userId]);
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
