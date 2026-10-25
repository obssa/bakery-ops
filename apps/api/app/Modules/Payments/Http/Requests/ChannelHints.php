<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

/**
 * The validation shared by the channel requests for the till's hints (phase-4 plan M3): reference_hint_en/_am and photo_hint_en/_am,
 * each at most 120 characters, and each a bilingual pair (rule 9): a request that names one of a pair names both, and either both are
 * text or both are null (no hint). An empty string is no text (the framework turns it into null).
 */
final class ChannelHints
{
    /** @var list<string> */
    public const array FIELDS = ['reference_hint_en', 'reference_hint_am', 'photo_hint_en', 'photo_hint_am'];

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $rules = [];
        foreach (['reference_hint', 'photo_hint'] as $pair) {
            foreach (['en' => 'am', 'am' => 'en'] as $lang => $twin) {
                $rules["{$pair}_{$lang}"] = ["present_with:{$pair}_{$twin}", "required_with:{$pair}_{$twin}", 'nullable', 'string', 'max:120'];
            }
        }

        return $rules;
    }

    /** A validated text field as stored: trimmed, and null when nothing is left. */
    public static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
