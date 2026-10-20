<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Shared\StateMachine\StateMachine;

/**
 * The payment machine (domain-model.md, "State machines"): recorded -> verified | disputed, and recorded | verified ->
 * voided (a manager's PIN, with a reason). Phase 1 only ever writes `recorded`; the other moves are Phase 4's actions
 * (verify against the bank statement, dispute, void), but the table is final now so the exhaustive test pins it and
 * Phase 4 adds no column or state. The payments table enforces the same list with the CHECK constraint
 * payments_status_check.
 */
enum PaymentStatus: string
{
    use StateMachine;

    case Recorded = 'recorded';
    case Verified = 'verified';
    case Disputed = 'disputed';
    case Voided = 'voided';

    /** @return array<string, list<self>> */
    public static function transitions(): array
    {
        return [
            self::Recorded->value => [self::Verified, self::Disputed, self::Voided],
            self::Verified->value => [self::Voided],
            self::Disputed->value => [],
            self::Voided->value => [],
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
