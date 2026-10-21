<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Shared\I18n\Bilingual;

/**
 * One step of a recipe's method as the production card shows it: its place in the method (1, 2, 3...) and the text in
 * English and Amharic (rule 9).
 */
final readonly class RecipeStepView
{
    public function __construct(
        public int $order,
        public Bilingual $text,
    ) {}

    /**
     * @return array{order: int, text_en: string, text_am: string}
     */
    public function toArray(): array
    {
        return ['order' => $this->order, 'text_en' => $this->text->en, 'text_am' => $this->text->am];
    }
}
