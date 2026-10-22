<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /shift-templates/{template}: any of the fields of a template. Instances already made keep their own window.
 */
final class UpdateTemplateRequest extends FormRequest
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
            'name_en' => ['sometimes', 'required', 'string', 'max:80'],
            'name_am' => ['sometimes', 'required', 'string', 'max:80'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'end_time' => ['sometimes', 'required', 'date_format:H:i'],
            'days_of_week' => ['sometimes', 'required', 'array', 'min:1', 'max:7'],
            'days_of_week.*' => ['integer', 'between:1,7', 'distinct'],
            'station_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'station_ids.*' => ['integer', 'min:1', 'distinct'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = $this->validated();
        foreach (['name_en', 'name_am'] as $field) {
            if (isset($changes[$field])) {
                $changes[$field] = trim((string) $changes[$field]);
            }
        }

        return $changes;
    }
}
