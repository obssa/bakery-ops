<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * A payment channel as the dashboards name it (Payments\Contracts\Facts::channels, step 4.4c): its code and both names, whether it is
 * switched on, and whether it was removed since it took the money a summary counts. Plain values, no model (phase-4 plan section 2).
 */
final readonly class ChannelFact
{
    public function __construct(
        public int $channelId,
        public string $code,
        public string $nameEn,
        public string $nameAm,
        public bool $isEnabled,
        public bool $isRemoved,
    ) {}

    /**
     * @return array{channel_id: int, code: string, name_en: string, name_am: string, is_enabled: bool, is_removed: bool}
     */
    public function toArray(): array
    {
        return [
            'channel_id' => $this->channelId,
            'code' => $this->code,
            'name_en' => $this->nameEn,
            'name_am' => $this->nameAm,
            'is_enabled' => $this->isEnabled,
            'is_removed' => $this->isRemoved,
        ];
    }
}
