<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Cash;

use App\Modules\Shifts\Exceptions\ShiftError;
use App\Shared\Values\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * The notes and coins a cashier counts (phase-3 plan section 10.5): the list in config('shifts.denominations') (the ETB notes 200, 100, 50,
 * 10, 5, 1 and coins 0.50, 0.25, 0.10, 0.05), a non-negative whole number of each, and a total the server works out in Money, never a
 * float and never sent by the client.
 *
 * normalise() turns what was sent into the canonical map: every configured denomination, in the configured order, written as the
 * configuration spells it ("0.50", not "0.5"), with a whole count (zero where none was sent). A key that equals a configured denomination
 * numerically is accepted whatever its spelling ("0.5", 100.00); anything else is 422 shifts.cash.denomination_unknown; a count that is
 * not an integer (or a numeric string of digits) between 0 and MAX_COUNT, or the same denomination twice, is 422 shifts.cash.count_invalid
 * naming the denomination. Stateless apart from the configuration read; resolved per use.
 */
final class Denominations
{
    /** More than this many notes of one kind is not a count a drawer holds: refused as a typing mistake. */
    public const int MAX_COUNT = 1_000_000;

    /**
     * @return list<string> the configured denominations, as configured
     */
    public function list(): array
    {
        $list = config('shifts.denominations');

        return is_array($list) ? array_values(array_map(static fn (mixed $d): string => (string) $d, $list)) : [];
    }

    /**
     * @param  array<array-key, mixed>  $counts  what was sent: denomination => count
     * @return array<string, int> every configured denomination => count
     *
     * @throws ShiftError shifts.cash.denomination_unknown, shifts.cash.count_invalid
     */
    public function normalise(array $counts): array
    {
        $list = $this->list();
        $out = array_fill_keys($list, 0);
        $seen = [];

        foreach ($counts as $key => $count) {
            $given = (string) $key;
            $canonical = $this->canonical($given, $list);
            if ($canonical === null) {
                throw ShiftError::denominationUnknown($given);
            }
            if (isset($seen[$canonical])) {
                throw ShiftError::cashCountInvalid($canonical);
            }
            $seen[$canonical] = true;
            $out[$canonical] = $this->count($count, $canonical);
        }

        return $out;
    }

    /**
     * The sum of denomination x count, exact.
     *
     * @param  array<string, int>  $normalised  as normalise() answers
     */
    public function total(array $normalised): Money
    {
        $total = Money::zero();
        foreach ($normalised as $denomination => $count) {
            $total = $total->plus(Money::of((string) $denomination)->multipliedBy($count));
        }

        return $total;
    }

    /**
     * The configured spelling of a denomination sent in any spelling, or null when it is not on the list.
     *
     * @param  list<string>  $list
     */
    private function canonical(string $given, array $list): ?string
    {
        if ($given === '' || preg_match('/\A\d+(\.\d+)?\z/', $given) !== 1) {
            return null;
        }

        try {
            $value = BigDecimal::of($given);
            foreach ($list as $denomination) {
                if ($value->isEqualTo(BigDecimal::of($denomination))) {
                    return $denomination;
                }
            }
        } catch (MathException) {
            return null;
        }

        return null;
    }

    /**
     * @throws ShiftError shifts.cash.count_invalid
     */
    private function count(mixed $count, string $denomination): int
    {
        if (is_string($count) && preg_match('/\A\d{1,7}\z/', $count) === 1) {
            $count = (int) $count;
        }
        if (! is_int($count) || $count < 0 || $count > self::MAX_COUNT) {
            throw ShiftError::cashCountInvalid($denomination);
        }

        return $count;
    }
}
