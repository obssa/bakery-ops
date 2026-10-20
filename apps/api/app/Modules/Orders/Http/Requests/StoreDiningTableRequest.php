<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /dining-tables: a table of the admin branch with its code (what orders snapshot as table_code; letters, digits,
 * dash and underscore, up to 16), both names (rule 9), optionally seats and a place in the grid. Whether the code is
 * free is checked by the controller (orders.table.code_taken), against inactive tables too.
 */
final class StoreDiningTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');
        if (is_string($code)) {
            $this->merge(['code' => trim($code)]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/'],
            'name_en' => ['required', 'string', 'max:120'],
            'name_am' => ['required', 'string', 'max:120'],
            'seats' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:32767'],
        ];
    }

    /**
     * @return array{code: string, name_en: string, name_am: string, seats: int|null, sort: int}
     */
    public function tableAttributes(): array
    {
        $data = $this->validated();

        return [
            'code' => (string) $data['code'],
            'name_en' => (string) $data['name_en'],
            'name_am' => (string) $data['name_am'],
            'seats' => isset($data['seats']) ? (int) $data['seats'] : null,
            'sort' => (int) ($data['sort'] ?? 0),
        ];
    }
}
