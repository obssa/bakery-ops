<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The money of a refund was paid back (phase-4 plan section 5, step 4.3d): Payments wrote its payment_refunds rows, one per payment the
 * refund went back through (cash out of the drawer of the shift it was given in, or a transfer made outside the app). Dispatched by
 * Payments' refund inside the refund's transaction and delivered after the commit, so a refund that rolled back is never announced.
 * Analytics touches the day it happened (TouchToday: the refund figures count on their own day); Notifications' NotifyRefund tells the
 * people who check the money. The order number and the approver's name are carried so a listener writes its text from the payload alone
 * (Notifications reads neither Orders nor Payments). Ids, decimal strings, the reason and a name only: never the PIN, the transfer's
 * reference or a link to its photo.
 */
final class RefundPaid extends DomainEvent implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<array{payment_id: int, channel_code: string, amount: string}>  $paymentRefunds  the shares, in the order they were paid
     */
    public function __construct(
        int $branchId,
        public readonly int $refundId,
        public readonly int $orderId,
        public readonly ?int $orderNo,
        public readonly string $amount,
        public readonly bool $isFull,
        public readonly string $reason,
        public readonly int $byUserId,
        public readonly int $approvedByUserId,
        public readonly string $approvedByName,
        public readonly array $paymentRefunds,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($branchId, $occurredAt);
    }

    /**
     * @return array{refund_id: int, order_id: int, order_no: int|null, amount: string, is_full: bool, reason: string, by_user_id: int, approved_by_user_id: int, approved_by_name: string, payment_refunds: list<array{payment_id: int, channel_code: string, amount: string}>}
     */
    public function payload(): array
    {
        return [
            'refund_id' => $this->refundId,
            'order_id' => $this->orderId,
            'order_no' => $this->orderNo,
            'amount' => $this->amount,
            'is_full' => $this->isFull,
            'reason' => $this->reason,
            'by_user_id' => $this->byUserId,
            'approved_by_user_id' => $this->approvedByUserId,
            'approved_by_name' => $this->approvedByName,
            'payment_refunds' => $this->paymentRefunds,
        ];
    }
}
