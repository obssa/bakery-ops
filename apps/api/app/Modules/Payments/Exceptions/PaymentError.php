<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal Payments makes, as a stable message_key (lang/<locale>/payments.php, rule 9) plus the
 * HTTP status it maps to and what it is about (the channel, the amounts, the order's status). Thrown inside an
 * AuditedAction it rolls the whole write back; Laravel renders it through render().
 */
final class PaymentError extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        public readonly string $messageKey,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($messageKey);
    }

    /** The person acting by short code does not hold payments.take (the same answer as the permission middleware's). */
    public static function forbidden(): self
    {
        return new self('auth.forbidden', 403);
    }

    public static function channelUnknown(string $code): self
    {
        return new self('payments.channel.unknown', 422, ['field' => 'channel_code', 'channel_code' => $code]);
    }

    public static function channelDisabled(string $code): self
    {
        return new self('payments.channel.disabled', 422, ['field' => 'channel_code', 'channel_code' => $code]);
    }

    /** Every channel that needs a receipt photo, until Phase 4 builds the upload. */
    public static function photoRequired(string $code): self
    {
        return new self('payments.channel.photo_required', 422, ['field' => 'channel_code', 'channel_code' => $code]);
    }

    public static function referenceRequired(string $code): self
    {
        return new self('payments.channel.reference_required', 422, ['field' => 'reference_no', 'channel_code' => $code]);
    }

    /** Phase 1 takes the whole balance in one payment: a part payment and an overpayment are both refused. */
    public static function amountMismatch(string $balance, string $amount): self
    {
        return new self('payments.amount.mismatch', 422, ['field' => 'amount', 'balance' => $balance, 'amount' => $amount]);
    }

    public static function tenderedTooLow(string $amount, string $tendered): self
    {
        return new self('payments.tendered.too_low', 422, ['field' => 'tendered_amount', 'amount' => $amount, 'tendered_amount' => $tendered]);
    }

    /** An open cart, a cancelled, voided or closed order takes no payment. */
    public static function notPayable(string $status): self
    {
        return new self('payments.order.not_payable', 409, ['status' => $status]);
    }

    /** The balance is already paid in full (before the food came): the order closes when its last line is served. */
    public static function settled(): self
    {
        return new self('payments.order.settled', 409);
    }

    /** A client UUID names one payment, of one order. */
    public static function clientUuidReused(): self
    {
        return new self('payments.client_uuid.reused', 409, ['field' => 'client_uuid']);
    }

    /**
     * payments.require_shift_for_cash is on and the actor carries no open shift (none at all, or one that is counting or locked):
     * the cash would be in no drawer's total.
     */
    public static function shiftRequired(): self
    {
        return new self('payments.shift.required', 409);
    }

    /** Step 4.3a: a channel code the branch already has, a removed channel's included (the unique key is (branch, code)). */
    public static function codeTaken(string $code): self
    {
        return new self('payments.channel.code_taken', 422, ['field' => 'code', 'code' => $code]);
    }

    /**
     * A reference pattern ReferencePattern refuses; the reason is one of ReferencePattern::REASONS (too_long, does_not_compile,
     * unsupported, nested_quantifier, backtracks).
     */
    public static function patternInvalid(string $reason): self
    {
        return new self('payments.channel.pattern_invalid', 422, ['field' => 'reference_pattern', 'reason' => $reason]);
    }

    /**
     * The cash channel stays on, stays, and asks for no photo, reference or pattern: the drawer count is built on it. $field is the
     * change that was refused (null for a removal).
     */
    public static function cashLocked(?string $field): self
    {
        return new self('payments.channel.cash_locked', 409, ($field === null ? [] : ['field' => $field]) + ['channel_code' => 'cash']);
    }

    /**
     * A new order must name every live channel of the branch exactly once.
     *
     * @param  list<int>  $missing  live channels the list leaves out
     * @param  list<int>  $unexpected  ids that are not a live channel of the branch, or that come twice
     */
    public static function orderIncomplete(array $missing, array $unexpected): self
    {
        return new self('payments.channel.order_incomplete', 422, ['field' => 'ids', 'missing' => $missing, 'unexpected' => $unexpected]);
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
