<?php

declare(strict_types=1);

namespace App\Modules\Shifts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /variance-reasons/{reason}: any of the names, the note rule, the switch and the order. The code never changes: a `code` in the
 * body is not a rule here, so validated() drops it.
 */
final class UpdateVarianceReasonRequest extends FormRequest
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
            'requires_note' => ['sometimes', 'boolean'],
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
        foreach (['requires_note', 'is_active'] as $field) {
            if (array_key_exists($field, $changes)) {
                $changes[$field] = (bool) $changes[$field];
            }
        }

        return $changes;
    }
}
