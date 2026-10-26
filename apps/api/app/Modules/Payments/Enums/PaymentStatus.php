<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Shared\StateMachine\StateMachine;

/**
 * The payment machine (domain-model.md, "State machines"): recorded -> verified | disputed, recorded | verified -> voided (a manager's PIN,
 * with a reason), and since step 4.3c disputed -> verified | voided (a dispute is resolved: the payment is found after all, or it is voided;
 * phase-4 plan 10.8 R-Q). Phase 1 only ever writes `recorded`; the other moves are Phase 4's actions (VerifyPayment, DisputePayment,
 * VoidPayment), and the exhaustive tests pin the table (Phase1StateMachinesTest, tests/Unit/Payments/PaymentStatusTest). Phase 4 adds no
 * state; its M4 (step 4.3b) added the dispute columns. The payments table enforces the same list with the CHECK constraint payments_status_check.
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
            self::Disputed->value => [self::Verified, self::Voided],
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
