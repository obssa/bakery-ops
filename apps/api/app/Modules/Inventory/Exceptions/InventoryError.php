<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal Inventory makes, as a stable message_key (lang/<locale>/inventory.php, rule 9) plus the
 * HTTP status it maps to. Thrown inside a ledger transaction it rolls every movement of the batch back; Laravel renders
 * it through render().
 */
final class InventoryError extends RuntimeException
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

    /** The movements would take on_hand at a location below zero. */
    public static function insufficientStock(int $itemId, int $locationId, string $onHand, string $wouldBe): self
    {
        return new self('inventory.stock.insufficient', 409, [
            'item_id' => $itemId, 'location_id' => $locationId, 'on_hand' => $onHand, 'would_be' => $wouldBe,
        ]);
    }

    /** The movements would release or consume more than is reserved (a reservation settled twice). */
    public static function reservedBelowZero(int $itemId, int $locationId, string $reserved, string $wouldBe): self
    {
        return new self('inventory.stock.reserved_below_zero', 409, [
            'item_id' => $itemId, 'location_id' => $locationId, 'reserved' => $reserved, 'would_be' => $wouldBe,
        ]);
    }

    /** A movement changes on_hand at a station that tracks shifts while neither the actor's shift nor the station's is open. */
    public static function shiftRequired(int $stationId, int $locationId): self
    {
        return new self('inventory.shift.required', 409, ['station_id' => $stationId, 'location_id' => $locationId]);
    }

    public static function reservationNotFound(string $ref): self
    {
        return new self('inventory.reservation.not_found', 404, ['reservation_ref' => $ref]);
    }

    /** A released reservation cannot be consumed any more. */
    public static function reservationReleased(string $ref): self
    {
        return new self('inventory.reservation.released', 409, ['reservation_ref' => $ref]);
    }

    /** A settled reservation (released or consumed) cannot be reserved again under the same ref. */
    public static function reservationClosed(string $ref): self
    {
        return new self('inventory.reservation.closed', 409, ['reservation_ref' => $ref]);
    }

    /** A to-order product without an active recipe cannot be made, so it cannot be reserved. */
    public static function recipeMissing(int $itemId): self
    {
        return new self('inventory.recipe.missing', 422, ['item_id' => $itemId]);
    }

    /** The same client_uuid already names a different movement. */
    public static function clientUuidReused(int $movementId): self
    {
        return new self('inventory.movement.client_uuid_reused', 409, ['movement_id' => $movementId]);
    }

    /** The item already has a valued average: the opening cost only states the cost of stock that has none. */
    public static function costExists(int $itemId): self
    {
        return new self('inventory.cost.exists', 409, ['item_id' => $itemId]);
    }

    /** The item never had a cost (no purchase, no opening cost). */
    public static function costNotFound(int $itemId): self
    {
        return new self('inventory.cost.not_found', 404, ['item_id' => $itemId]);
    }

    /** The person asking for a station's stock strip neither works at that station nor at every station. */
    public static function stationForbidden(): self
    {
        return new self('inventory.station.forbidden', 403);
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
