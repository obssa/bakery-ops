<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /dining-tables/{table}: rename (both languages stay filled, rule 9), change the seats or the place in the grid,
 * or switch the table off and on (is_active). The code is fixed: orders snapshot it.
 */
final class UpdateDiningTableRequest extends FormRequest
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
            'seats' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed> only the attributes present in the request
     */
    public function changes(): array
    {
        $data = $this->validated();
        $out = [];
        foreach (['name_en', 'name_am'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = (string) $data[$key];
            }
        }
        if (array_key_exists('seats', $data)) {
            $out['seats'] = $data['seats'] === null ? null : (int) $data['seats'];
        }
        if (array_key_exists('sort', $data)) {
            $out['sort'] = (int) $data['sort'];
        }
        if (array_key_exists('is_active', $data)) {
            $out['is_active'] = (bool) $data['is_active'];
        }

        return $out;
    }
}
