<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /brand (Phase 6 follow-up): the business name the app shows, in English and in Amharic (rule 9), both or neither, up to 120 each.
 * Neither (both empty or null) puts the app's own name back. Anything else is 422 validation.failed.
 */
final class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name_en' => ['present', 'nullable', 'string', 'max:120', 'required_with:name_am'],
            'name_am' => ['present', 'nullable', 'string', 'max:120', 'required_with:name_en'],
        ];
    }

    /** @return array{0: string|null, 1: string|null} the name pair, trimmed, null when not given */
    public function names(): array
    {
        $data = $this->validated();
        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        return [$text($data['name_en'] ?? null), $text($data['name_am'] ?? null)];
    }
}
