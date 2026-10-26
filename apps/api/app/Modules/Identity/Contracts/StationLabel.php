<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * A station as a dashboard names it (ActorDirectory::stations, step 4.4c): its code, both names, and whether it is still in use (a
 * removed station is still named, as it was when the summaries counted it). Plain values, no model.
 */
final readonly class StationLabel
{
    public function __construct(
        public int $id,
        public string $code,
        public string $nameEn,
        public string $nameAm,
        public bool $isActive,
    ) {}

    /**
     * @return array{id: int, code: string, name_en: string, name_am: string, is_active: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_en' => $this->nameEn,
            'name_am' => $this->nameAm,
            'is_active' => $this->isActive,
        ];
    }
}
