<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Shared\DomainRefusal;
use App\Shared\Http\ApiError;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Every client-facing refusal Payments makes, as a stable message_key (lang/<locale>/payments.php, rule 9) plus the
 * HTTP status it maps to and what it is about (the channel, the amounts, the order's status). Thrown inside an
 * AuditedAction it rolls the whole write back; Laravel renders it through render().
 */
final class PaymentError extends RuntimeException implements DomainRefusal
{
    /** @var list<string> why a receipt_file_id is refused (photoInvalid) */
    public const array PHOTO_REASONS = ['not_found', 'wrong_purpose', 'attached', 'expired'];

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

    /** A channel that needs a receipt photo, asked without a receipt_file_id (Phase 1 refused every such channel; step 4.3b takes the photo). */
    public static function photoRequired(string $code): self
    {
        return new self('payments.channel.photo_required', 422, ['field' => 'receipt_file_id', 'channel_code' => $code]);
    }

    /**
     * The receipt_file_id cannot be this payment's photo. $reason is one of PHOTO_REASONS: not_found (unknown, another branch's or removed),
     * wrong_purpose (not a receipt), attached (it proves another payment, or another row of the same batch) or expired (uploaded more than
     * payments.photo_max_age_hours ago).
     */
    public static function photoInvalid(string $reason): self
    {
        return new self('payments.photo.invalid', 422, ['field' => 'receipt_file_id', 'reason' => $reason]);
    }

    public static function referenceRequired(string $code): self
    {
        return new self('payments.channel.reference_required', 422, ['field' => 'reference_no', 'channel_code' => $code]);
    }

    /** The reference does not match the channel's pattern; the channel's hint pair says what one looks like (null when none is set). */
    public static function referenceInvalid(string $code, ?string $hintEn, ?string $hintAm): self
    {
        return new self('payments.reference.invalid', 422, ['field' => 'reference_no', 'channel_code' => $code, 'hint_en' => $hintEn, 'hint_am' => $hintAm]);
    }

    /** More than the balance (step 4.3b: a part payment, 0 < amount < balance, is taken; Phase 1 refused it too). The key is kept for the Phase 1 client. */
    public static function amountMismatch(string $balance, string $amount): self
    {
        return new self('payments.amount.mismatch', 422, ['field' => 'amount', 'balance' => $balance, 'amount' => $amount]);
    }

    /** A batch's amounts must add up to exactly the balance. */
    public static function batchSumMismatch(string $balance, string $sum): self
    {
        return new self('payments.batch.sum_mismatch', 422, ['field' => 'rows', 'balance' => $balance, 'sum' => $sum]);
    }

    /** Row $row (0-based) of a batch would be refused on its own: the whole batch is, with the row's own refusal as `error`. */
    public static function batchRowInvalid(int $row, self $error): self
    {
        return new self('payments.batch.row_invalid', 422, ['field' => "rows.{$row}", 'row' => $row, 'error' => $error->body()]);
    }

    /**
     * The bilingual body of this refusal ({message_key, message_en, message_am, ...extra}).
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return ApiError::body($this->messageKey, $this->extra);
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

    /** A client UUID names one payment (or one batch), of one order. $field names it in a batch (rows.1.client_uuid). */
    public static function clientUuidReused(string $field = 'client_uuid'): self
    {
        return new self('payments.client_uuid.reused', 409, ['field' => $field]);
    }

    /**
     * The payment needs the till's shift (Support\ShiftRule: cash with payments.require_shift_for_cash, every channel with
     * payments.require_shift, which follows the cash switch unless it is set) and the actor carries no open shift (none at all, or one
     * that is counting or locked): the money would be in no drawer's or channel's total of a shift.
     */
    public static function shiftRequired(): self
    {
        return new self('payments.shift.required', 409);
    }

    /**
     * Step 4.3c: the payment's order is paid or closed, so the sale is finished and the payment is not voided: the order is refunded instead
     * (Orders' ReversePayment refuses it with the same key, OrderError::paymentVoidOrderClosed). $status is the order's.
     */
    public static function voidOrderClosed(string $status): self
    {
        return new self('payments.void.order_closed', 409, ['status' => $status]);
    }

    /** Step 4.3c: money already went back from this payment (refunded_amount, step 4.3d's refunds), so voiding it would count the money twice. */
    public static function voidRefunded(string $refundedAmount): self
    {
        return new self('payments.void.refunded', 409, ['refunded_amount' => $refundedAmount]);
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

    /**
     * Step 4.3e: an order that is not paid in full has no receipt (a pro-forma may be asked for with ?proforma=1); a cancelled or a voided
     * order has none at all. $status is the order's OrderStatus value.
     */
    public static function receiptUnpaid(string $status): self
    {
        return new self('payments.receipt.unpaid', 409, ['status' => $status]);
    }

    /** Step 4.3e: GET /payments/reconciliation reads at most payments.reconciliation_max_days local days at once. */
    public static function reconciliationRangeTooLarge(int $maxDays): self
    {
        return new self('payments.reconciliation.range_too_large', 422, ['field' => 'from', 'max_days' => $maxDays]);
    }

    /** Step 4.3e: GET /payments/export writes at most payments.export_max_rows rows; $rows is how many the filters find. */
    public static function exportTooLarge(int $maxRows, int $rows): self
    {
        return new self('payments.export.too_large', 422, ['max_rows' => $maxRows, 'rows' => $rows]);
    }

    /**
     * Step 4.3d: an `allocation` of a refund that cannot be followed. $reason: `sum` (the shares do not add up to the refund), `payment`
     * (a payment that is not one of the order's, or voided), `exceeds` (more than the payment still holds: amount - refunded_amount),
     * `repeated` (one payment named twice); $paymentId names the share at fault, when one is.
     */
    public static function refundAllocationInvalid(string $reason, ?int $paymentId = null): self
    {
        return new self('payments.refund.allocation_invalid', 422, ['field' => 'allocation', 'reason' => $reason, 'payment_id' => $paymentId]);
    }

    /** Step 4.3d: a refund pays a share back through a digital channel, a transfer made outside the app: its reference is asked for. */
    public static function refundReferenceRequired(string $channelCode): self
    {
        return new self('payments.refund.reference_required', 422, ['field' => 'refund_reference', 'channel_code' => $channelCode]);
    }

    /**
     * Step 4.3d: the refund_proof_file_id cannot be this refund's photo: `not_found` (unknown, another branch's, removed), `wrong_purpose`
     * (not a refund_proof upload), `attached` (claimed already), `expired` (older than payments.photo_max_age_hours).
     */
    public static function refundProofInvalid(string $reason): self
    {
        return new self('payments.refund.proof_invalid', 422, ['field' => 'refund_proof_file_id', 'reason' => $reason]);
    }

    /** DomainRefusal (step 5.5a): the key the info line carries instead of a stack trace. */
    public function messageKey(): string
    {
        return $this->messageKey;
    }

    /** DomainRefusal (step 5.5a): a 4xx is a refusal and is not reported; a 5xx is a failure and is. */
    public function httpStatus(): int
    {
        return $this->status;
    }

    public function render(): JsonResponse
    {
        return ApiError::json($this->messageKey, $this->status, $this->extra);
    }
}
