<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/**
 * One charged line of an order as a receipt prints it (Orders::forReceipt, step 4.3e): the product in both languages, the quantity (scale 4)
 * and the money (scale 2) as decimal strings, and the modifiers as they were written with the line (in the order they were written).
 */
final readonly class ReceiptLineView
{
    /**
     * @param  list<array{name_en: string, name_am: string, price_delta: string}>  $modifiers
     */
    public function __construct(
        public int $lineId,
        public int $itemId,
        public string $nameEn,
        public string $nameAm,
        public string $qty,
        public string $unitPrice,
        public string $modifierPrice,
        public string $lineTotal,
        public array $modifiers,
    ) {}

    /**
     * @return array{line_id: int, item_id: int, name_en: string, name_am: string, qty: string, unit_price: string, modifier_price: string, line_total: string, modifiers: list<array{name_en: string, name_am: string, price_delta: string}>}
     */
    public function toArray(): array
    {
        return [
            'line_id' => $this->lineId,
            'item_id' => $this->itemId,
            'name_en' => $this->nameEn,
            'name_am' => $this->nameAm,
            'qty' => $this->qty,
            'unit_price' => $this->unitPrice,
            'modifier_price' => $this->modifierPrice,
            'line_total' => $this->lineTotal,
            'modifiers' => $this->modifiers,
        ];
    }
}
