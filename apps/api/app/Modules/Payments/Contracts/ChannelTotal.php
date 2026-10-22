<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * What one payment channel took in one shift (Totals::byChannel): plain values, no model. `receipts` is how many
 * payments, `sum` their amounts as a 2 dp decimal string (change given back is not counted: it is the payment's amount).
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
    ) {}

    /**
     * @return array{channel_id: int, code: string, name_en: string, name_am: string, receipts: int, sum: string}
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
        ];
    }
}
