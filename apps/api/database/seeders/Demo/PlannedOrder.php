<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

/**
 * One order of the Phase 4 day plan (DayPlan::phaseFour, step 4.6a): taken at the shared pad at $time, made at the stations, served,
 * then paid as the plan says. Everything here is decided when the plan is drawn (mt_srand(DayPlan::SEED)), so the same days come out
 * on every run; only the quantities of a stock-out order are read from the ledger when it is taken.
 *
 *   $lines        [SKU, quantity in the item's base unit]; empty for the stock-out order (all that is left of $stockOut)
 *   $voidOrder    voided after it was sent, before anything was made (VoidOrder, the manager approves)
 *   $channel      the channel of the payment that settles it (a digital channel for a split: its digital part)
 *   $split        one bill over two channels: a digital part with its photo, the rest in cash (RecordPaymentBatch)
 *   $splitPct     the digital part's share of the total, in percent
 *   $voidPayment  a digital part payment taken before the order is paid, voided (the manager approves), then the bill paid in cash
 *   $check        what the manager does with the digital payment at the end of the day: none, verify, dispute, or dispute and verify the
 *                 next morning (dispute_resolved); none for cash, a voided order and an order whose part payment is voided
 *   $refund       refunded after it closed: always false until refunds exist (DayPlan::REFUND_PCT, step 4.3d)
 *   $offsets      minutes after $time: [accept, bar ready, kitchen ready, serve after the last ready, pay after serving]
 *   $stockOut     the stock-out order of the day: it takes every unit of this product left in the display case
 */
final readonly class PlannedOrder
{
    public const string CHECK_NONE = 'none';

    public const string CHECK_VERIFY = 'verify';

    public const string CHECK_DISPUTE = 'dispute';

    public const string CHECK_DISPUTE_RESOLVED = 'dispute_resolved';

    /**
     * @param  list<array{0: string, 1: string}>  $lines
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int}  $offsets
     */
    public function __construct(
        public int $n,
        public string $time,
        public string $table,
        public string $waiter,
        public array $lines,
        public bool $voidOrder,
        public string $channel,
        public bool $split,
        public int $splitPct,
        public bool $voidPayment,
        public string $check,
        public bool $refund,
        public array $offsets,
        public ?string $stockOut = null,
    ) {}

    /** Is the payment that settles it on a channel other than cash (its photo and reference, the manager's check)? */
    public function digital(): bool
    {
        return $this->channel !== 'cash';
    }

    /**
     * A copy with some of the plan's choices changed.
     *
     * @param  list<array{0: string, 1: string}>|null  $lines
     */
    public function with(
        ?bool $voidOrder = null,
        ?string $channel = null,
        ?bool $split = null,
        ?bool $voidPayment = null,
        ?string $check = null,
        ?array $lines = null,
        ?string $stockOut = null,
    ): self {
        return new self(
            $this->n,
            $this->time,
            $this->table,
            $this->waiter,
            $lines ?? $this->lines,
            $voidOrder ?? $this->voidOrder,
            $channel ?? $this->channel,
            $split ?? $this->split,
            $this->splitPct,
            $voidPayment ?? $this->voidPayment,
            $check ?? $this->check,
            $this->refund,
            $this->offsets,
            $stockOut ?? $this->stockOut,
        );
    }
}
