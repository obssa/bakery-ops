<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /suppliers: who a purchase is bought from. The name is a proper noun, shown as typed (no _en / _am pair); phone,
 * TIN and notes are optional.
 */
final class StoreSupplierRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array{name: string, phone: string|null, tin: string|null, notes: string|null}
     */
    public function supplierAttributes(): array
    {
        $data = $this->validated();

        return [
            'name' => trim((string) $data['name']),
            'phone' => self::optional($data['phone'] ?? null),
            'tin' => self::optional($data['tin'] ?? null),
            'notes' => self::optional($data['notes'] ?? null),
        ];
    }

    private static function optional(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
