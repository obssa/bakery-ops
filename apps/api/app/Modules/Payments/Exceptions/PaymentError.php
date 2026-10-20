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

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
