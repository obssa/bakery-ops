<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * A payment channel as other modules and the till see it (Channels::enabled, ::find): plain values, no model. Step 4.3a adds the
 * channel's reference pattern and its bilingual hints after the Phase 1 fields (additive: the Phase 1 constructor arguments and the
 * first nine keys of toArray() are unchanged, so every Phase 1 to 3 reader keeps working).
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
        public ?string $referencePattern = null,
        public ?string $referenceHintEn = null,
        public ?string $referenceHintAm = null,
        public ?string $photoHintEn = null,
        public ?string $photoHintAm = null,
    ) {}

    /** Whether the till can take money on it today: enabled, and no receipt photo needed (the photo attach is step 4.3b). */
    public function acceptedNow(): bool
    {
        return $this->isEnabled && ! $this->requiresPhoto;
    }

    /** The cash channel: the drawer's money, locked to taking cash. */
    public function isCash(): bool
    {
        return $this->code === 'cash';
    }

    /**
     * @return array{id: int, code: string, name_en: string, name_am: string, is_enabled: bool, requires_photo: bool, requires_reference: bool, sort: int, accepted_now: bool, reference_pattern: string|null, reference_hint_en: string|null, reference_hint_am: string|null, photo_hint_en: string|null, photo_hint_am: string|null, is_cash: bool}
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
            'reference_pattern' => $this->referencePattern,
            'reference_hint_en' => $this->referenceHintEn,
            'reference_hint_am' => $this->referenceHintAm,
            'photo_hint_en' => $this->photoHintEn,
            'photo_hint_am' => $this->photoHintAm,
            'is_cash' => $this->isCash(),
        ];
    }
}
