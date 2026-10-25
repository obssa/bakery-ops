<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /payment-channels/{channel}: any of the names, the switch, the two rules, the reference pattern (null or an empty string
 * clears it), the hints (a pair is changed together) and the place in the order. The code never changes: a body that names one is
 * refused (422 validation.failed on `code`), so a client never believes it renamed a code. The pattern's safety and the cash lock are
 * Payments' rules (SaveChannel).
 */
final class UpdateChannelRequest extends FormRequest
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
            'code' => ['prohibited'],
            'name_en' => ['sometimes', 'required', 'string', 'max:120'],
            'name_am' => ['sometimes', 'required', 'string', 'max:120'],
            'is_enabled' => ['sometimes', 'boolean'],
            'requires_photo' => ['sometimes', 'boolean'],
            'requires_reference' => ['sometimes', 'boolean'],
            'reference_pattern' => ['sometimes', 'nullable', 'string'],
            ...ChannelHints::rules(),
            'sort' => ['sometimes', 'integer', 'min:0', 'max:32767'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = $this->validated();
        unset($changes['code']);
        foreach (['name_en', 'name_am'] as $field) {
            if (isset($changes[$field])) {
                $changes[$field] = trim((string) $changes[$field]);
            }
        }
        foreach (['is_enabled', 'requires_photo', 'requires_reference'] as $field) {
            if (array_key_exists($field, $changes)) {
                $changes[$field] = (bool) $changes[$field];
            }
        }
        foreach (['reference_pattern', ...ChannelHints::FIELDS] as $field) {
            if (array_key_exists($field, $changes)) {
                $changes[$field] = ChannelHints::text($changes[$field]);
            }
        }
        if (array_key_exists('sort', $changes)) {
            $changes['sort'] = (int) $changes['sort'];
        }

        return $changes;
    }
}
