<?php

declare(strict_types=1);

namespace App\Shared\Values;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Database\Eloquent\Castable;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of money: NUMERIC(12,2) with an ISO currency code (ETB by default), half-up at the second decimal,
 * a decimal string everywhere outside the database (ADR 0004). Immutable; never a float.
 */
final readonly class Money implements Castable, JsonSerializable, Stringable
{
    public const int SCALE = 2;

    public const string DEFAULT_CURRENCY = 'ETB';

    /** NUMERIC(12,2) leaves ten digits before the decimal point. */
    private const int INTEGER_DIGITS = 10;

    private function __construct(private BigDecimal $amount, private string $currency) {}

    public static function of(self|string|int $amount, ?string $currency = null): self
    {
        if ($amount instanceof self) {
            if ($currency !== null && self::currencyCode($currency) !== $amount->currency) {
                throw CurrencyMismatch::between($amount->currency, self::currencyCode($currency));
            }

            return $amount;
        }

        return new self(self::normalise(self::decimal($amount)), self::currencyCode($currency));
    }

    public static function zero(?string $currency = null): self
    {
        return new self(BigDecimal::zero()->toScale(self::SCALE), self::currencyCode($currency));
    }

    /** Fixed scale, e.g. "12.50". */
    public function amount(): string
    {
        return (string) $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function plus(self|string|int $other): self
    {
        return new self(self::normalise($this->amount->plus($this->operand($other))), $this->currency);
    }

    public function minus(self|string|int $other): self
    {
        return new self(self::normalise($this->amount->minus($this->operand($other))), $this->currency);
    }

    /** Price x quantity: the factor is a plain number or a Quantity, never money. */
    public function multipliedBy(Quantity|string|int $factor): self
    {
        $decimal = $factor instanceof Quantity ? $factor->toBigDecimal() : self::decimal($factor);

        return new self(self::normalise($this->amount->multipliedBy($decimal)), $this->currency);
    }

    public function negated(): self
    {
        return new self($this->amount->negated(), $this->currency);
    }

    public function abs(): self
    {
        return new self($this->amount->abs(), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function compareTo(self|string|int $other): int
    {
        return $this->amount->compareTo($this->operand($other));
    }

    /** Same currency and same amount. */
    public function equals(self|string|int $other): bool
    {
        if ($other instanceof self && $other->currency !== $this->currency) {
            return false;
        }

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
        return $this->amount;
    }

    public function toString(): string
    {
        return $this->amount();
    }

    public function __toString(): string
    {
        return $this->amount();
    }

    /** JSON carries the amount as a decimal string; the currency travels with the branch (ADR 0004). */
    public function jsonSerialize(): string
    {
        return $this->amount();
    }

    /**
     * @return array{amount: string, currency: string}
     */
    public function toArray(): array
    {
        return ['amount' => $this->amount(), 'currency' => $this->currency];
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return class-string<MoneyCast>
     */
    public static function castUsing(array $arguments): string
    {
        return MoneyCast::class;
    }

    private function operand(self|string|int $other): BigDecimal
    {
        if ($other instanceof self) {
            if ($other->currency !== $this->currency) {
                throw CurrencyMismatch::between($this->currency, $other->currency);
            }

            return $other->amount;
        }

        return self::decimal($other);
    }

    private static function currencyCode(?string $currency): string
    {
        $code = strtoupper(trim($currency ?? self::DEFAULT_CURRENCY));
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a three-letter currency code.', $code));
        }

        return $code;
    }

    private static function decimal(string|int $value): BigDecimal
    {
        try {
            return BigDecimal::of($value);
        } catch (MathException $e) {
            throw new InvalidArgumentException(sprintf('"%s" is not a decimal amount.', $value), 0, $e);
        }
    }

    private static function normalise(BigDecimal $value): BigDecimal
    {
        $scaled = $value->toScale(self::SCALE, RoundingMode::HalfUp);
        if (strlen(ltrim((string) $scaled->abs()->getIntegralPart(), '0')) > self::INTEGER_DIGITS) {
            throw NumericOverflow::for('Money', (string) $scaled, 12, self::SCALE);
        }

        return $scaled;
    }
}
