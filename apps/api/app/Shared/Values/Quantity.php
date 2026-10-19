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
 * A quantity in an item's base unit: NUMERIC(14,4), half-up at the fourth decimal, a decimal string everywhere
 * outside the database (ADR 0004). Immutable; a PHP float can never get in.
 */
final readonly class Quantity implements Castable, JsonSerializable, Stringable
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

    public function plus(self|string|int $other): self
    {
        return new self(self::normalise($this->value->plus(self::operand($other))));
    }

    public function minus(self|string|int $other): self
    {
        return new self(self::normalise($this->value->minus(self::operand($other))));
    }

    public function multipliedBy(self|string|int $factor): self
    {
        return new self(self::normalise($this->value->multipliedBy(self::operand($factor))));
    }

    public function dividedBy(self|string|int $divisor): self
    {
        try {
            return new self(self::normalise($this->value->dividedBy(self::operand($divisor), self::SCALE, RoundingMode::HalfUp)));
        } catch (DivisionByZeroException $e) {
            throw new InvalidArgumentException('A quantity cannot be divided by zero.', 0, $e);
        }
    }

    public function negated(): self
    {
        return new self($this->value->negated());
    }

    public function abs(): self
    {
        return new self($this->value->abs());
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function compareTo(self|string|int $other): int
    {
        return $this->value->compareTo(self::operand($other));
    }

    public function equals(self|string|int $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isGreaterThan(self|string|int $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isGreaterThanOrEqualTo(self|string|int $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function isLessThan(self|string|int $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isLessThanOrEqualTo(self|string|int $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->value;
    }

    /** Fixed scale, e.g. "12.5000". */
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
     * @return class-string<QuantityCast>
     */
    public static function castUsing(array $arguments): string
    {
        return QuantityCast::class;
    }

    private static function decimal(string|int $value): BigDecimal
    {
        try {
            return BigDecimal::of($value);
        } catch (MathException $e) {
            throw new InvalidArgumentException(sprintf('"%s" is not a decimal quantity.', $value), 0, $e);
        }
    }

    private static function operand(self|string|int $value): BigDecimal
    {
        return $value instanceof self ? $value->value : self::decimal($value);
    }

    private static function normalise(BigDecimal $value): BigDecimal
    {
        $scaled = $value->toScale(self::SCALE, RoundingMode::HalfUp);
        if (strlen(ltrim((string) $scaled->abs()->getIntegralPart(), '0')) > self::INTEGER_DIGITS) {
            throw NumericOverflow::for('Quantity', (string) $scaled, 14, self::SCALE);
        }

        return $scaled;
    }
}
