<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Http\Rules\DecimalString;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /units: a global unit (every branch shares it), named in both languages (rule 9). A derived unit names a base
 * unit (one without a base of its own) and a positive factor as a decimal string; a base unit has factor 1.
 */
final class StoreUnitRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/', Rule::unique('units', 'code')],
            'name_en' => ['required', 'string', 'max:60'],
            'name_am' => ['required', 'string', 'max:60'],
            'base_unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('units', 'id')->whereNull('base_unit_id')->whereNull('deleted_at')],
            'factor' => [Rule::requiredIf(fn (): bool => $this->input('base_unit_id') !== null), 'nullable', DecimalString::quantity()],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $factor = $this->input('factor');
                if ($this->input('base_unit_id') !== null || $factor === null || $validator->errors()->has('factor')) {
                    return;
                }
                if (! (is_string($factor) || is_int($factor)) || ! BigDecimal::of((string) $factor)->isEqualTo(1)) {
                    $validator->errors()->add('factor', (string) trans('catalog.validation.base_factor'));
                }
            },
        ];
    }

    /**
     * @return array{code: string, name_en: string, name_am: string, base_unit_id: int|null, factor: string}
     */
    public function unitAttributes(): array
    {
        $data = $this->validated();
        $base = isset($data['base_unit_id']) ? (int) $data['base_unit_id'] : null;

        return [
            'code' => (string) $data['code'],
            'name_en' => (string) $data['name_en'],
            'name_am' => (string) $data['name_am'],
            'base_unit_id' => $base,
            'factor' => $base === null ? '1' : (string) $data['factor'],
        ];
    }
}
