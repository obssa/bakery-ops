<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * What one payment channel took in one shift (Totals::byChannel): plain values, no model. `receipts` is how many
 * payments, `sum` their amounts as a 2 dp decimal string (change given back is not counted: it is the payment's amount); `refunds` how many
 * refunds were paid out through the channel in the shift and `refundTotal` their amounts (step 4.3d).
 */
final readonly class ChannelTotal
{
    public function __construct(
        public int $channelId,
        public string $code,
        public string $nameEn,
        public string $nameAm,
        public int $receipts,
        public string $sum,
        public int $refunds = 0,
        public string $refundTotal = '0.00',
    ) {}

    /**
     * @return array{channel_id: int, code: string, name_en: string, name_am: string, receipts: int, sum: string, refunds: int, refund_total: string}
     */
    public function toArray(): array
    {
        return [
            'channel_id' => $this->channelId,
            'code' => $this->code,
            'name_en' => $this->nameEn,
            'name_am' => $this->nameAm,
            'receipts' => $this->receipts,
            'sum' => $this->sum,
            'refunds' => $this->refunds,
            'refund_total' => $this->refundTotal,
        ];
    }
}
