<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /suppliers/{supplier}: any of the supplier fields; a field left out stays, null clears an optional one. A
 * supplier is switched off with is_active = false (a purchase can no longer be started with it); there is no delete.
 */
final class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $data = $this->validated();
        $changes = [];
        foreach (['name', 'phone', 'tin', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = is_string($data[$field]) ? trim($data[$field]) : null;
                $changes[$field] = $value === '' ? null : $value;
            }
        }
        if (array_key_exists('is_active', $data)) {
            $changes['is_active'] = (bool) $data['is_active'];
        }

        return $changes;
    }
}
