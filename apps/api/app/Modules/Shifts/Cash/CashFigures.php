<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Cash;

use App\Shared\Values\Money;

/**
 * The money figures of one cash declaration, worked out by CashDeclarations (live for a draft, the stored ones from the submit on).
 * `channels` is the reconciliation table, ordered by channel code, each row
 * {code, name_en, name_am, expected_receipts, expected_sum, declared_receipts, declared_sum, difference_sum} with the declared figures
 * and the difference null where the cashier declared nothing for the channel. `differs` is true when the cash is not what was expected
 * or any declared channel differs from what it took: the count then needs a reason, and waits for a decision.
 */
final readonly class CashFigures
{
    /**
     * @param  list<array{code: string, name_en: string, name_am: string, expected_receipts: int, expected_sum: string, declared_receipts: int|null, declared_sum: string|null, difference_sum: string|null}>  $channels
     */
    public function __construct(
        public Money $counted,
        public Money $expected,
        public Money $variance,
        public ?Money $carriedFloat,
        public ?Money $cashSales,
        public ?Money $floatLeft,
        public array $channels,
        public bool $differs,
    ) {}
}
