<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/**
 * An item as a dashboard names it (Items::labels, step 4.4c): both names, ingredient or product, the base unit its quantities are counted in,
 * its category with both names (null for none; a removed category is still named), whether it is new today in the branch's calendar, and
 * whether it is live (a deleted or switched-off item is still named, as it was when the summaries counted it). Plain values, no model.
 */
final readonly class ItemLabel
{
    public function __construct(
        public int $id,
        public string $nameEn,
        public string $nameAm,
        public string $kind,
        public string $unit,
        public ?int $categoryId,
        public ?string $categoryNameEn,
        public ?string $categoryNameAm,
        public bool $isNew,
        public bool $isActive,
    ) {}

    /**
     * @return array{id: int, name_en: string, name_am: string, kind: string, unit: string, category: array{id: int, name_en: string, name_am: string}|null, is_new: bool, is_active: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name_en' => $this->nameEn,
            'name_am' => $this->nameAm,
            'kind' => $this->kind,
            'unit' => $this->unit,
            'category' => $this->categoryId === null ? null : ['id' => $this->categoryId, 'name_en' => (string) $this->categoryNameEn, 'name_am' => (string) $this->categoryNameAm],
            'is_new' => $this->isNew,
            'is_active' => $this->isActive,
        ];
    }
}
