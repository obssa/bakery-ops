<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /payment-channels: the client's id for the channel (ADR 0003), a code (lower case letters, digits, underscore and hyphen, at
 * most 32; chosen once, it is what the till sends), both names (at most 120), the two rules, and optionally whether it starts
 * enabled, a reference pattern, the hints (each a pair: both languages or neither, at most 120) and its place in the order. Whether
 * the code is free and the pattern safe, and the cash lock, are Payments' rules (SaveChannel), answered with their own message keys.
 */
final class StoreChannelRequest extends FormRequest
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
            'client_uuid' => ['required', 'string', 'uuid'],
            'code' => ['required', 'string', 'max:32', 'regex:/\A[a-z0-9][a-z0-9_-]*\z/'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_am' => ['required', 'string', 'max:120'],
            'requires_photo' => ['required', 'boolean'],
            'requires_reference' => ['required', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'reference_pattern' => ['sometimes', 'nullable', 'string'],
            ...ChannelHints::rules(),
            'sort' => ['sometimes', 'integer', 'min:0', 'max:32767'],
        ];
    }

    /**
     * @return array{client_uuid: string, code: string, name_en: string, name_am: string, requires_photo: bool, requires_reference: bool, is_enabled?: bool, reference_pattern?: string|null, reference_hint_en?: string|null, reference_hint_am?: string|null, photo_hint_en?: string|null, photo_hint_am?: string|null, sort?: int}
     */
    public function fields(): array
    {
        $v = $this->validated();
        $fields = [
            'client_uuid' => strtolower((string) $v['client_uuid']),
            'code' => (string) $v['code'],
            'name_en' => trim((string) $v['name_en']),
            'name_am' => trim((string) $v['name_am']),
            'requires_photo' => (bool) $v['requires_photo'],
            'requires_reference' => (bool) $v['requires_reference'],
        ];
        if (array_key_exists('is_enabled', $v)) {
            $fields['is_enabled'] = (bool) $v['is_enabled'];
        }
        if (array_key_exists('reference_pattern', $v)) {
            $fields['reference_pattern'] = ChannelHints::text($v['reference_pattern']);
        }
        foreach (ChannelHints::FIELDS as $field) {
            if (array_key_exists($field, $v)) {
                $fields[$field] = ChannelHints::text($v[$field]);
            }
        }
        if (array_key_exists('sort', $v)) {
            $fields['sort'] = (int) $v['sort'];
        }

        return $fields;
    }
}
