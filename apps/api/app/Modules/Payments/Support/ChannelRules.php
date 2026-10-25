<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Exceptions\PaymentError;
use App\Modules\Payments\Models\PaymentChannel;

/**
 * The two rules every channel write checks (phase-4 plan section 4.2, step 4.3a), in one place so create, edit and removal agree:
 *   - a reference pattern must be one ReferencePattern accepts (422 payments.channel.pattern_invalid with the reason);
 *   - the cash channel (code `cash`) keeps the till able to take cash: it stays enabled and is never removed, and it asks for no
 *     photo, no reference and no pattern (409 payments.channel.cash_locked). Its names, its place in the order and its hints are
 *     the admin's to change. The drawer count (Totals::cash, the till's cash declaration) is built on it.
 */
final class ChannelRules
{
    /**
     * @throws PaymentError payments.channel.pattern_invalid (422)
     */
    public static function assertPattern(?string $pattern): void
    {
        if ($pattern === null) {
            return;
        }
        $problem = ReferencePattern::problem($pattern);
        if ($problem !== null) {
            throw PaymentError::patternInvalid($problem);
        }
    }

    /**
     * The state a channel would have after the write, checked against the cash lock.
     *
     * @throws PaymentError payments.channel.cash_locked (409)
     */
    public static function assertCashKeepsWorking(string $code, bool $enabled, bool $requiresPhoto, bool $requiresReference, ?string $pattern): void
    {
        if ($code !== PaymentChannel::CASH) {
            return;
        }
        $broken = match (true) {
            ! $enabled => 'is_enabled',
            $requiresPhoto => 'requires_photo',
            $requiresReference => 'requires_reference',
            $pattern !== null => 'reference_pattern',
            default => null,
        };
        if ($broken !== null) {
            throw PaymentError::cashLocked($broken);
        }
    }

    /**
     * @throws PaymentError payments.channel.cash_locked (409)
     */
    public static function assertRemovable(PaymentChannel $channel): void
    {
        if ($channel->isCash()) {
            throw PaymentError::cashLocked(null);
        }
    }
}
