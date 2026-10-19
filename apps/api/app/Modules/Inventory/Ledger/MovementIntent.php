<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Ledger;

use App\Shared\Values\Quantity;
use Illuminate\Support\Str;

/**
 * One movement a caller asks the Ledger to write: the item, the location whose balance it changes, the signed qty in
 * base units, the reason, what caused it (ref_type / ref_id), the reservation it belongs to and the client UUID of an
 * offline record. Validated on construction (MovementReason::effect), so an intent that exists is one the Ledger can
 * write as far as signs go; stock levels are the Ledger's to check.
 *
 * A transfer is two intents built together by transfer(): the side leaving (-qty at the source) and the side arriving
 * (+qty at the destination), each knowing the other end; the Ledger writes both or neither.
 */
final readonly class MovementIntent
{
    public Quantity $qty;

    public function __construct(
        public int $itemId,
        public int $locationId,
        Quantity|string|int $qty,
        public MovementReason $reason,
        public string $refType,
        public ?int $refId = null,
        public ?string $reservationRef = null,
        public ?string $clientUuid = null,
        public ?int $counterpartLocationId = null,
    ) {
        $this->qty = Quantity::of($qty);
        $reason->effect($this->qty, $reservationRef !== null);

        if ($refType === '' || strlen($refType) > 64) {
            throw InvalidMovement::because('ref_type names what caused the movement (1 to 64 characters)');
        }
        if ($reservationRef !== null && ! Str::isUuid($reservationRef)) {
            throw InvalidMovement::because('a reservation_ref is a UUID');
        }
        if ($clientUuid !== null && ! Str::isUuid($clientUuid)) {
            throw InvalidMovement::because('a client_uuid is a UUID');
        }
        if (($reason === MovementReason::Transfer) !== ($counterpartLocationId !== null)) {
            throw InvalidMovement::because('a transfer, and only a transfer, names the other end; build one with MovementIntent::transfer()');
        }
        if ($counterpartLocationId === $locationId) {
            throw InvalidMovement::because('a transfer moves stock between two different locations');
        }
    }

    public static function of(
        int $itemId,
        int $locationId,
        Quantity|string|int $qty,
        MovementReason $reason,
        string $refType,
        ?int $refId = null,
        ?string $reservationRef = null,
        ?string $clientUuid = null,
    ): self {
        return new self($itemId, $locationId, $qty, $reason, $refType, $refId, $reservationRef, $clientUuid);
    }

    /**
     * Both sides of moving $qty (> 0) of an item from one location to another.
     *
     * @return array{0: self, 1: self} the side leaving $fromLocationId, then the side arriving at $toLocationId
     */
    public static function transfer(int $itemId, int $fromLocationId, int $toLocationId, Quantity|string|int $qty, string $refType, ?int $refId = null): array
    {
        $qty = Quantity::of($qty);
        if (! $qty->isPositive()) {
            throw InvalidMovement::because('a transfer moves a positive quantity');
        }

        return [
            new self($itemId, $fromLocationId, $qty->negated(), MovementReason::Transfer, $refType, $refId, null, null, $toLocationId),
            new self($itemId, $toLocationId, $qty, MovementReason::Transfer, $refType, $refId, null, null, $fromLocationId),
        ];
    }

    public function effect(): MovementEffect
    {
        return $this->reason->effect($this->qty, $this->reservationRef !== null);
    }

    /** The location the stock leaves (or is held at, for the reservation reasons); null when stock arrives. */
    public function fromLocationId(): ?int
    {
        if ($this->counterpartLocationId !== null) {
            return $this->qty->isNegative() ? $this->locationId : $this->counterpartLocationId;
        }

        return $this->reason->needsReservationRef() || $this->qty->isNegative() ? $this->locationId : null;
    }

    /** The location the stock arrives at; null when it leaves or is held. */
    public function toLocationId(): ?int
    {
        if ($this->counterpartLocationId !== null) {
            return $this->qty->isNegative() ? $this->counterpartLocationId : $this->locationId;
        }

        return $this->reason->needsReservationRef() || $this->qty->isNegative() ? null : $this->locationId;
    }
}
