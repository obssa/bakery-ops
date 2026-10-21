<?php

declare(strict_types=1);

namespace App\Shared\Values;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\DivisionByZeroException;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Database\Eloquent\Castable;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A cost rate: what one base unit of an item costs, NUMERIC(14,4), half-up at the fourth decimal, a decimal string
 * everywhere outside the database (ADR 0004, note of 2026-10-21; invariants version 2). A cost per gram (0.0850 ETB)
 * cannot be held as Money, which has two decimals. A rate times a Quantity is Money, rounded half-up to two decimals
 * once (times). Immutable; never negative, never a float.
 */
final readonly class UnitCost implements Castable, JsonSerializable, Stringable
{
    public const int SCALE = 4;

    /** NUMERIC(14,4) leaves ten digits before the decimal point. */
    private const int INTEGER_DIGITS = 10;

    private function __construct(private BigDecimal $value) {}

    public static function of(self|string|int $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return new self(self::normalise(self::decimal($value)));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero()->toScale(self::SCALE));
    }

    /** This rate for $qty base units, as money in $currency (default ETB), half-up to two decimals once. */
    public function times(Quantity|string|int $qty, ?string $currency = null): Money
    {
        $factor = $qty instanceof Quantity ? $qty->toBigDecimal() : self::decimal($qty);
        $exact = $this->value->multipliedBy($factor)->toScale(Money::SCALE, RoundingMode::HalfUp);

        return Money::of((string) $exact, $currency);
    }

    /** The same cost spread over a larger unit's worth of base units (a price per kg to a price per g: divide by 1000). */
    public function dividedBy(Quantity|string|int $divisor): self
    {
        $by = $divisor instanceof Quantity ? $divisor->toBigDecimal() : self::decimal($divisor);
        try {
            return new self(self::normalise($this->value->dividedBy($by, self::SCALE, RoundingMode::HalfUp)));
        } catch (DivisionByZeroException $e) {
            throw new InvalidArgumentException('A cost rate cannot be divided by zero.', 0, $e);
        }
    }

    public function multipliedBy(Quantity|string|int $factor): self
    {
        $by = $factor instanceof Quantity ? $factor->toBigDecimal() : self::decimal($factor);

        return new self(self::normalise($this->value->multipliedBy($by)));
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function compareTo(self|string|int $other): int
    {
        return $this->value->compareTo(self::of($other)->value);
    }

    public function equals(self|string|int $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isGreaterThan(self|string|int $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self|string|int $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->value;
    }

    /** Fixed scale, e.g. "0.0850". */
    public function toString(): string
    {
        return (string) $this->value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return class-string<UnitCostCast>
     */
    public static function castUsing(array $arguments): string
    {
        return UnitCostCast::class;
    }

    private static function decimal(string|int $value): BigDecimal
    {
        try {
            return BigDecimal::of($value);
        } catch (MathException $e) {
            throw new InvalidArgumentException(sprintf('"%s" is not a decimal cost.', $value), 0, $e);
        }
    }

    private static function normalise(BigDecimal $value): BigDecimal
    {
        $scaled = $value->toScale(self::SCALE, RoundingMode::HalfUp);
        if ($scaled->isNegative()) {
            throw new InvalidArgumentException(sprintf('A cost rate cannot be negative (%s).', $scaled));
        }
        if (strlen(ltrim((string) $scaled->getIntegralPart(), '0')) > self::INTEGER_DIGITS) {
            throw NumericOverflow::for('UnitCost', (string) $scaled, 14, self::SCALE);
        }

        return $scaled;
    }
}
