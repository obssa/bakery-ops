<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * A payment channel as other modules and the till see it (Channels::enabled, ::find): plain values, no model.
 */
final readonly class ChannelView
{
    public function __construct(
        public int $id,
        public string $code,
        public string $nameEn,
        public string $nameAm,
        public bool $isEnabled,
        public bool $requiresPhoto,
        public bool $requiresReference,
        public int $sort,
    ) {}

    /** Whether the till can take money on it today: enabled, and no receipt photo needed (the photo is Phase 4). */
    public function acceptedNow(): bool
    {
        return $this->isEnabled && ! $this->requiresPhoto;
    }

    /**
     * @return array{id: int, code: string, name_en: string, name_am: string, is_enabled: bool, requires_photo: bool, requires_reference: bool, sort: int, accepted_now: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_en' => $this->nameEn,
            'name_am' => $this->nameAm,
            'is_enabled' => $this->isEnabled,
            'requires_photo' => $this->requiresPhoto,
            'requires_reference' => $this->requiresReference,
            'sort' => $this->sort,
            'accepted_now' => $this->acceptedNow(),
        ];
    }
}
