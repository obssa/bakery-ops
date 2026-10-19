<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Ledger;

use App\Shared\Values\Quantity;

/**
 * Why stock moved, and the one map from a reason to its effect on (on_hand, reserved) (domain-model.md, Inventory;
 * traps.md, Reserved accounting). qty is signed and in the item's base unit:
 *
 *   purchase_in, production_output, return          qty > 0   on_hand += qty
 *   waste                                           qty < 0   on_hand += qty
 *   transfer (one row per side), count_adjustment   qty <> 0  on_hand += qty
 *   reservation                                     qty > 0   reserved += qty            (reservation_ref required)
 *   reservation_release                             qty < 0   reserved += qty            (reservation_ref required)
 *   production_consume, sale                        qty < 0   on_hand += qty, and reserved += qty when the row
 *                                                             carries the reservation_ref it consumes
 *
 * item_stock_view (migration 2026_10_19_210400) recomputes the same map in SQL; the Phase 0 gate proves the two agree.
 */
enum MovementReason: string
{
    case PurchaseIn = 'purchase_in';
    case Transfer = 'transfer';
    case ProductionConsume = 'production_consume';
    case ProductionOutput = 'production_output';
    case Sale = 'sale';
    case Waste = 'waste';
    case CountAdjustment = 'count_adjustment';
    case Returned = 'return';
    case Reservation = 'reservation';
    case ReservationRelease = 'reservation_release';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }

    /**
     * The reasons a person records by hand in Phase 0 (POST /stock/movements). Transfers and purchases with suppliers
     * get their own workflows in Phase 2; sales, consumption and reservations come from Orders and Production.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::PurchaseIn, self::Waste, self::Returned, self::CountAdjustment];
    }

    /** 'in' (qty > 0), 'out' (qty < 0) or 'either' (qty <> 0). */
    public function direction(): string
    {
        return match ($this) {
            self::PurchaseIn, self::ProductionOutput, self::Returned, self::Reservation => 'in',
            self::Waste, self::ProductionConsume, self::Sale, self::ReservationRelease => 'out',
            self::Transfer, self::CountAdjustment => 'either',
        };
    }

    /** reservation and reservation_release move reserved only, and always belong to a reservation. */
    public function needsReservationRef(): bool
    {
        return $this === self::Reservation || $this === self::ReservationRelease;
    }

    /** The reasons a reservation_ref may sit on: the two reservation reasons and the consumptions that settle one. */
    public function canCarryReservationRef(): bool
    {
        return $this->needsReservationRef() || $this === self::ProductionConsume || $this === self::Sale;
    }

    /**
     * What a movement of $qty for this reason does to (on_hand, reserved).
     *
     * @throws InvalidMovement when the sign contradicts the reason, qty is zero, or the reservation_ref is missing
     *                         where it is required or present where it has no meaning
     */
    public function effect(Quantity|string|int $qty, bool $withReservationRef = false): MovementEffect
    {
        $qty = Quantity::of($qty);
        if ($qty->isZero()) {
            throw InvalidMovement::because("a {$this->value} movement cannot be zero");
        }
        $direction = $this->direction();
        if ($direction === 'in' && $qty->isNegative()) {
            throw InvalidMovement::because("a {$this->value} movement brings stock in: qty must be positive, got {$qty}");
        }
        if ($direction === 'out' && $qty->isPositive()) {
            throw InvalidMovement::because("a {$this->value} movement takes stock out: qty must be negative, got {$qty}");
        }
        if ($this->needsReservationRef() && ! $withReservationRef) {
            throw InvalidMovement::because("a {$this->value} movement belongs to a reservation: reservation_ref is required");
        }
        if ($withReservationRef && ! $this->canCarryReservationRef()) {
            throw InvalidMovement::because("a {$this->value} movement cannot carry a reservation_ref");
        }

        $zero = Quantity::zero();

        return match (true) {
            $this->needsReservationRef() => new MovementEffect($zero, $qty),
            $withReservationRef => new MovementEffect($qty, $qty),
            default => new MovementEffect($qty, $zero),
        };
    }
}
